<?php
$title = 'Edit Domain';
$pageTitle = 'Edit Domain';
$pageDescription = htmlspecialchars($domain['domain_name']);
$pageIcon = 'fas fa-edit';
ob_start();
?>

<!-- Main Form -->
<div class="max-w-3xl mx-auto">
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                <i class="fas fa-cog text-gray-400 mr-2 text-sm"></i>
                Domain Settings
            </h2>
        </div>
        
        <div class="p-6">
            <form method="POST" action="/domains/<?= $domain['id'] ?>/update" class="space-y-5">
                <?= csrf_field() ?>

                <!-- Domain Name (Read-only) -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">
                        Domain Name
                    </label>
                    <div class="relative">
                        <input type="text" 
                               class="w-full px-3 py-2.5 border border-gray-300 rounded-lg bg-gray-50 text-gray-600 cursor-not-allowed text-sm" 
                               value="<?= htmlspecialchars($domain['domain_name']) ?>" 
                               disabled>
                        <div class="absolute right-3 top-1/2 transform -translate-y-1/2">
                            <i class="fas fa-lock text-gray-400 text-xs"></i>
                        </div>
                    </div>
                    <p class="mt-1.5 text-xs text-gray-500">
                        Domain name cannot be changed after creation
                    </p>
                </div>

                <!-- Tags -->
                <div>
                    <label for="tags-input" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Tags
                        <span class="text-gray-400 font-normal">(Optional)</span>
                    </label>
                    
                    <!-- Tag Display Area -->
                    <div id="tags-display" class="min-h-[40px] p-2 border border-gray-300 rounded-lg mb-2 flex flex-wrap gap-1.5 bg-gray-50"></div>
                    
                    <!-- Tag Input -->
                    <div class="relative">
                        <i class="fas fa-tag absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" 
                               id="tags-input" 
                               class="w-full pl-10 pr-20 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm" 
                               placeholder="Type any tag and press Enter or comma..."
                               onkeydown="handleTagInput(event)">
                        <button type="button" onclick="addTagFromInput()" class="absolute right-2 top-1/2 transform -translate-y-1/2 px-3 py-1 bg-primary text-white text-xs rounded hover:bg-primary-dark">
                            Add
                        </button>
                    </div>
                    
                    <!-- Hidden input to store tags for form submission -->
                    <input type="hidden" id="tags" name="tags" value="<?= htmlspecialchars($domain['tags'] ?? '') ?>">
                    
                    <p class="mt-1.5 text-xs text-gray-500">
                        <i class="fas fa-info-circle mr-1"></i>
                        Type any custom tag (letters, numbers, hyphens). Press <kbd class="px-1 py-0.5 bg-gray-200 rounded text-xs">Enter</kbd> or <kbd class="px-1 py-0.5 bg-gray-200 rounded text-xs">,</kbd> to add.
                    </p>
                    
                    <!-- Available Tags -->
                    <div class="mt-2">
                        <p class="text-xs text-gray-600 mb-1.5">💡 Available Tags:</p>
                        <div class="flex flex-wrap gap-1.5">
                            <?php foreach ($availableTags as $tag): ?>
                                <button type="button" onclick="addTag('<?= htmlspecialchars($tag['name']) ?>')" 
                                        class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium border <?= htmlspecialchars($tag['color']) ?> hover:opacity-80 transition-colors">
                                    <i class="fas fa-plus mr-1" style="font-size: 8px;"></i>
                                    <?= htmlspecialchars($tag['name']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Notification Group -->
                <div>
                    <label for="notification_group_id" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Notification Group
                    </label>
                    <select id="notification_group_id" 
                            name="notification_group_id" 
                            class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm">
                        <option value="">-- No Group (No notifications) --</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?= $group['id'] ?>" 
                                    <?= $domain['notification_group_id'] == $group['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($group['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-1.5 text-xs text-gray-500">
                        Change the notification group or remove it to stop receiving alerts
                    </p>
                </div>

                <!-- Manual Expiration Date -->
                <div>
                    <label for="manual_expiration_date" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Manual Expiration Date
                        <span class="text-gray-400 font-normal">(Optional)</span>
                    </label>
                    <div class="relative">
                        <i class="fas fa-calendar-alt absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="date" 
                               id="manual_expiration_date" 
                               name="manual_expiration_date" 
                               value="<?= $domain['expiration_date'] ? date('Y-m-d', strtotime($domain['expiration_date'])) : '' ?>"
                               class="w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm">
                    </div>
                    <p class="mt-1.5 text-xs text-gray-500">
                        <i class="fas fa-info-circle mr-1"></i>
                        Set a manual expiration date if WHOIS/RDAP doesn't provide one (e.g., for .nl domains). 
                        This will be used for expiration notifications and status calculations.
                    </p>
                    <?php if ($domain['expiration_date']): ?>
                        <p class="mt-1 text-xs text-green-600">
                            <i class="fas fa-check-circle mr-1"></i>
                            Current expiration date: <?= $domain['expiration_date'] ? date('M j, Y', strtotime($domain['expiration_date'])) : 'Unknown' ?>
                        </p>
                    <?php else: ?>
                        <p class="mt-1 text-xs text-amber-600">
                            <i class="fas fa-exclamation-triangle mr-1"></i>
                            No expiration date available from WHOIS/RDAP. Consider setting a manual date.
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Active Monitoring -->
                <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" 
                               name="is_active" 
                               <?= $domain['is_active'] ? 'checked' : '' ?> 
                               class="w-4 h-4 text-primary border-gray-300 rounded focus:ring-primary cursor-pointer">
                        <div class="ml-3">
                            <span class="text-sm font-medium text-gray-900">Enable Active Monitoring</span>
                            <p class="text-xs text-gray-600 mt-0.5">When enabled, this domain will be checked regularly and notifications will be sent</p>
                        </div>
                    </label>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row gap-3 pt-3">
                    <button type="submit" 
                            class="inline-flex items-center justify-center px-5 py-2.5 bg-primary hover:bg-primary-dark text-white rounded-lg font-medium transition-colors text-sm">
                        <i class="fas fa-save mr-2"></i>
                        Update Domain
                    </button>
                    <a href="<?= htmlspecialchars($referrer ?? '/domains/' . $domain['id']) ?>" 
                       class="inline-flex items-center justify-center px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50 transition-colors text-sm">
                        <i class="fas fa-times mr-2"></i>
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-3">
        <a href="/domains/<?= $domain['id'] ?>" 
           class="flex items-center justify-center p-3 bg-white border border-gray-200 rounded-lg hover:border-blue-300 hover:bg-blue-50 transition-colors group">
            <i class="fas fa-eye text-blue-600 mr-2 text-sm"></i>
            <span class="text-sm font-medium text-gray-700">View Details</span>
        </a>
        <form method="POST" action="/domains/<?= $domain['id'] ?>/refresh" class="m-0">
            <?= csrf_field() ?>
            <button type="submit" 
                    class="w-full flex items-center justify-center p-3 bg-white border border-gray-200 rounded-lg hover:border-green-300 hover:bg-green-50 transition-colors group">
                <i class="fas fa-sync-alt text-green-600 mr-2 text-sm"></i>
                <span class="text-sm font-medium text-gray-700">Refresh WHOIS</span>
            </button>
        </form>
        <form method="POST" action="/domains/<?= $domain['id'] ?>/delete" onsubmit="return confirm('Delete this domain permanently?')" class="m-0">
            <?= csrf_field() ?>
            <button type="submit" 
                    class="w-full flex items-center justify-center p-3 bg-white border border-gray-200 rounded-lg hover:border-red-300 hover:bg-red-50 transition-colors group">
                <i class="fas fa-trash text-red-600 mr-2 text-sm"></i>
                <span class="text-sm font-medium text-gray-700">Delete Domain</span>
            </button>
        </form>
    </div>
</div>

<script>
// Initialize tags from existing domain data
const existingTags = '<?= htmlspecialchars($domain['tags'] ?? '') ?>';
let tags = existingTags ? existingTags.split(',').map(t => t.trim().toLowerCase()).filter(t => t) : [];

// Available tags with their colors from the database
const availableTags = <?= json_encode($availableTags) ?>;
const tagColors = {};
availableTags.forEach(tag => {
    tagColors[tag.name] = tag.color;
});

function addTag(tagName) {
    tagName = tagName.trim().toLowerCase();
    
    // Validate tag (alphanumeric and hyphens only)
    if (!/^[a-z0-9-]+$/.test(tagName)) {
        return;
    }
    
    // Check if tag already exists
    if (tags.includes(tagName)) {
        return;
    }
    
    tags.push(tagName);
    updateTagsDisplay();
    updateHiddenInput();
    
    // Clear input
    document.getElementById('tags-input').value = '';
}

function removeTag(tagName) {
    tags = tags.filter(t => t !== tagName);
    updateTagsDisplay();
    updateHiddenInput();
}

function updateTagsDisplay() {
    const display = document.getElementById('tags-display');
    display.innerHTML = '';
    
    if (tags.length === 0) {
        display.innerHTML = '<span class="text-xs text-gray-400 italic">No tags added yet</span>';
        return;
    }
    
    tags.forEach(tag => {
        const colorClass = tagColors[tag] || 'bg-gray-100 text-gray-700 border-gray-300';
        const tagElement = document.createElement('span');
        tagElement.className = `inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium border ${colorClass}`;
        tagElement.innerHTML = `
            <i class="fas fa-tag mr-1" style="font-size: 9px;"></i>
            ${tag}
            <button type="button" onclick="removeTag('${tag}')" class="ml-1.5 hover:text-red-600">
                <i class="fas fa-times" style="font-size: 9px;"></i>
            </button>
        `;
        display.appendChild(tagElement);
    });
}

function updateHiddenInput() {
    document.getElementById('tags').value = tags.join(',');
}

function handleTagInput(event) {
    if (event.key === 'Enter' || event.key === ',') {
        event.preventDefault();
        addTagFromInput();
    }
}

function addTagFromInput() {
    const input = document.getElementById('tags-input');
    const value = input.value.trim();
    
    if (value) {
        // Handle multiple tags separated by commas
        const newTags = value.split(',').map(t => t.trim().toLowerCase()).filter(t => t);
        newTags.forEach(tag => addTag(tag));
        input.value = '';
    }
}

// Initialize display
updateTagsDisplay();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>
