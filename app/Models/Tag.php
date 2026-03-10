<?php

namespace App\Models;

use Core\Model;

class Tag extends Model
{
    protected static string $table = 'tags';
    protected $fillable = ['name', 'color', 'description'];

    /**
     * Find tag by ID
     */
    public function find(int $id): ?array
    {
        $sql = "SELECT * FROM tags WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get all tags with usage count
     */
    public function getAllWithUsage(?int $userId = null): array
    {
        $sql = "SELECT t.*, 
                       COALESCE(usage_stats.usage_count, 0) as usage_count
                FROM tags t
                LEFT JOIN (
                    SELECT dt.tag_id, COUNT(*) as usage_count
                    FROM domain_tags dt
                    JOIN domains d ON d.id = dt.domain_id";
        
        $params = [];
        if ($userId) {
            $sql .= " WHERE d.user_id = ?";
            $params[] = $userId;
        }
        
        $sql .= "                    GROUP BY dt.tag_id
                ) usage_stats ON t.id = usage_stats.tag_id";
        
        // Add WHERE clause for tag visibility
        if ($userId) {
            $sql .= " WHERE (t.user_id = ? OR t.user_id IS NULL)";
            $params[] = $userId;
        }
        
        $sql .= " ORDER BY usage_count DESC, t.name ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get tags for a specific domain
     */
    public function getForDomain(int $domainId): array
    {
        $sql = "SELECT t.* FROM tags t
                JOIN domain_tags dt ON t.id = dt.tag_id
                WHERE dt.domain_id = ?
                ORDER BY t.name";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$domainId]);
        return $stmt->fetchAll();
    }

    /**
     * Add tag to domain
     */
    public function addToDomain(int $domainId, int $tagId): bool
    {
        try {
            $sql = "INSERT IGNORE INTO domain_tags (domain_id, tag_id) VALUES (?, ?)";
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([$domainId, $tagId]);
            
            if ($result) {
                $this->updateUsageCount($tagId);
            }
            
            return $result;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Remove tag from domain
     */
    public function removeFromDomain(int $domainId, int $tagId): bool
    {
        $sql = "DELETE FROM domain_tags WHERE domain_id = ? AND tag_id = ?";
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute([$domainId, $tagId]);
        
        if ($result) {
            $this->updateUsageCount($tagId);
        }
        
        return $result;
    }

    /**
     * Remove all tags from domain
     */
    public function removeAllFromDomain(int $domainId): bool
    {
        $sql = "DELETE FROM domain_tags WHERE domain_id = ?";
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute([$domainId]);
        
        if ($result) {
            // Update usage counts for all affected tags
            $this->updateAllUsageCounts();
        }
        
        return $result;
    }

    /**
     * Update tags for a domain (replace all existing tags)
     */
    public function updateDomainTags(int $domainId, string $tagsString, int $userId): bool
    {
        // Remove all existing tags from domain
        $this->removeAllFromDomain($domainId);
        
        if (empty(trim($tagsString))) {
            return true; // No tags to add
        }
        
        $tags = array_map('trim', explode(',', $tagsString));
        $tags = array_filter($tags); // Remove empty tags
        
        if (empty($tags)) {
            return true; // No valid tags to add
        }
        
        $added = 0;
        foreach ($tags as $tagName) {
            // Find or create tag
            $tag = $this->findByName($tagName, $userId);
            if (!$tag) {
                // Create new tag
                $tagId = $this->create([
                    'name' => $tagName,
                    'color' => 'bg-gray-100 text-gray-700 border-gray-300',
                    'description' => '',
                    'user_id' => $userId
                ]);
                if ($tagId) {
                    $this->addToDomain($domainId, $tagId);
                    $added++;
                }
            } else {
                // Use existing tag
                $this->addToDomain($domainId, $tag['id']);
                $added++;
            }
        }
        
        return $added > 0;
    }

    /**
     * Find tag by name for a specific user
     */
    public function findByName(string $name, int $userId): ?array
    {
        $sql = "SELECT * FROM tags WHERE name = ? AND (user_id = ? OR user_id IS NULL) ORDER BY user_id DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$name, $userId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Add tag to multiple domains
     */
    public function addToDomains(array $domainIds, int $tagId): int
    {
        $added = 0;
        foreach ($domainIds as $domainId) {
            if ($this->addToDomain($domainId, $tagId)) {
                $added++;
            }
        }
        return $added;
    }

    /**
     * Remove tag from multiple domains
     */
    public function removeFromDomains(array $domainIds, int $tagId): int
    {
        $removed = 0;
        foreach ($domainIds as $domainId) {
            if ($this->removeFromDomain($domainId, $tagId)) {
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Update usage count for a specific tag
     */
    public function updateUsageCount(int $tagId): void
    {
        $sql = "UPDATE tags SET usage_count = (
                    SELECT COUNT(*) FROM domain_tags WHERE tag_id = ?
                ) WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$tagId, $tagId]);
    }

    /**
     * Update usage counts for all tags
     */
    public function updateAllUsageCounts(): void
    {
        $sql = "UPDATE tags SET usage_count = (
                    SELECT COUNT(*) FROM domain_tags WHERE tag_id = tags.id
                )";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
    }

    /**
     * Get domains for a specific tag
     */
    public function getDomainsForTag(int $tagId, ?int $userId = null): array
    {
        $sql = "SELECT d.* FROM domains d
                JOIN domain_tags dt ON d.id = dt.domain_id
                WHERE dt.tag_id = ?";
        
        $params = [$tagId];
        
        if ($userId) {
            $sql .= " AND d.user_id = ?";
            $params[] = $userId;
        }
        
        $sql .= " ORDER BY d.domain_name";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Delete tag and all its relationships
     */
    public function deleteWithRelationships(int $tagId): bool
    {
        try {
            $this->db->beginTransaction();
            
            // Remove all domain relationships
            $sql = "DELETE FROM domain_tags WHERE tag_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$tagId]);
            
            // Delete the tag
            $sql = "DELETE FROM tags WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([$tagId]);
            
            $this->db->commit();
            return $result;
        } catch (\Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Remove custom (non-global) tags from a domain that don't belong to the specified user.
     * Global tags (user_id IS NULL) are preserved.
     */
    public function removeOtherUserTagsFromDomain(int $domainId, int $keepUserId): int
    {
        $sql = "DELETE dt FROM domain_tags dt
                JOIN tags t ON dt.tag_id = t.id
                WHERE dt.domain_id = ? AND t.user_id IS NOT NULL AND t.user_id != ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$domainId, $keepUserId]);
        $affected = $stmt->rowCount();

        if ($affected > 0) {
            $this->updateAllUsageCounts();
        }

        return $affected;
    }

    /**
     * Remove domain_tags for a tag where the domain doesn't belong to the specified user.
     */
    public function removeTagFromOtherUserDomains(int $tagId, int $keepUserId): int
    {
        $sql = "DELETE dt FROM domain_tags dt
                JOIN domains d ON dt.domain_id = d.id
                WHERE dt.tag_id = ? AND d.user_id != ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$tagId, $keepUserId]);
        $affected = $stmt->rowCount();

        if ($affected > 0) {
            $this->updateUsageCount($tagId);
        }

        return $affected;
    }

    /**
     * Remove custom (non-global) tags from all domains in a notification group
     * that don't belong to the specified user.
     */
    public function removeOtherUserTagsFromDomainsByGroup(int $groupId, int $keepUserId): int
    {
        $sql = "DELETE dt FROM domain_tags dt
                JOIN tags t ON dt.tag_id = t.id
                JOIN domains d ON dt.domain_id = d.id
                WHERE d.notification_group_id = ? AND t.user_id IS NOT NULL AND t.user_id != ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$groupId, $keepUserId]);
        $affected = $stmt->rowCount();

        if ($affected > 0) {
            $this->updateAllUsageCounts();
        }

        return $affected;
    }

    /**
     * Get available colors for tags
     */
    public function getAvailableColors(): array
    {
        return [
            'bg-gray-100 text-gray-700 border-gray-300' => 'Gray',
            'bg-red-100 text-red-700 border-red-300' => 'Red',
            'bg-orange-100 text-orange-700 border-orange-300' => 'Orange',
            'bg-yellow-100 text-yellow-700 border-yellow-300' => 'Yellow',
            'bg-green-100 text-green-700 border-green-300' => 'Green',
            'bg-blue-100 text-blue-700 border-blue-300' => 'Blue',
            'bg-indigo-100 text-indigo-700 border-indigo-300' => 'Indigo',
            'bg-purple-100 text-purple-700 border-purple-300' => 'Purple',
            'bg-pink-100 text-pink-700 border-pink-300' => 'Pink',
            'bg-teal-100 text-teal-700 border-teal-300' => 'Teal',
            'bg-cyan-100 text-cyan-700 border-cyan-300' => 'Cyan',
            'bg-lime-100 text-lime-700 border-lime-300' => 'Lime',
        ];
    }

    /**
     * Check if user can access a tag
     */
    public function canUserAccessTag(int $tagId, int $userId, bool $isolationMode = false): bool
    {
        if (!$isolationMode) {
            return true; // In shared mode, everyone can access all tags
        }

        $sql = "SELECT id FROM tags WHERE id = ? AND (user_id = ? OR user_id IS NULL)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$tagId, $userId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Assign all unassigned tags to a specific user (for isolation mode migration)
     */
    public function assignUnassignedTagsToUser(int $userId): int
    {
        $stmt = $this->db->prepare("UPDATE tags SET user_id = ? WHERE user_id IS NULL");
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    /**
     * Get tags for user isolation mode
     */
    public function getTagsForUser(int $userId, bool $isolationMode = false): array
    {
        if ($isolationMode) {
            $sql = "SELECT * FROM tags WHERE user_id = ? OR user_id IS NULL ORDER BY name";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId]);
        } else {
            $sql = "SELECT * FROM tags ORDER BY name";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
        }
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get filtered, sorted, and paginated tags
     */
    public function getFilteredPaginated(array $filters, string $sortBy, string $sortOrder, int $page, int $perPage, ?int $userId = null): array
    {
        // Get all tags with usage
        $tags = $this->getAllWithUsage($userId);

        // Apply search filter
        if (!empty($filters['search'])) {
            $tags = array_filter($tags, function($tag) use ($filters) {
                return stripos($tag['name'], $filters['search']) !== false ||
                       stripos($tag['description'] ?? '', $filters['search']) !== false;
            });
        }

        // Apply color filter
        if (!empty($filters['color'])) {
            $tags = array_filter($tags, function($tag) use ($filters) {
                return $tag['color'] === $filters['color'];
            });
        }

        // Apply type filter (global vs user)
        if (!empty($filters['type'])) {
            $tags = array_filter($tags, function($tag) use ($filters) {
                if ($filters['type'] === 'global') {
                    return $tag['user_id'] === null;
                } elseif ($filters['type'] === 'user') {
                    return $tag['user_id'] !== null;
                }
                return true;
            });
        }

        // Get total count after filtering
        $totalTags = count($tags);

        // Apply sorting
        usort($tags, function($a, $b) use ($sortBy, $sortOrder) {
            $aVal = $a[$sortBy] ?? '';
            $bVal = $b[$sortBy] ?? '';
            
            // Handle numeric sorting for usage_count
            if ($sortBy === 'usage_count') {
                $aVal = (int)$aVal;
                $bVal = (int)$bVal;
                $comparison = $aVal <=> $bVal;
            } else {
                $comparison = strcasecmp($aVal, $bVal);
            }
            
            return $sortOrder === 'desc' ? -$comparison : $comparison;
        });

        // Calculate pagination
        $totalPages = ceil($totalTags / $perPage);
        $page = min($page, max(1, $totalPages)); // Ensure page is within valid range
        $offset = ($page - 1) * $perPage;

        // Slice array for current page
        $paginatedTags = array_slice($tags, $offset, $perPage);

        return [
            'tags' => $paginatedTags,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalTags,
                'total_pages' => $totalPages,
                'showing_from' => $totalTags > 0 ? $offset + 1 : 0,
                'showing_to' => min($offset + $perPage, $totalTags)
            ]
        ];
    }
}
