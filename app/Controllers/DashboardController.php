<?php

namespace App\Controllers;

use Core\Controller;
use App\Models\Domain;
use App\Models\NotificationGroup;
use App\Models\NotificationLog;

class DashboardController extends Controller
{
    private Domain $domainModel;
    private NotificationGroup $groupModel;
    private NotificationLog $logModel;

    public function __construct()
    {
        $this->domainModel = new Domain();
        $this->groupModel = new NotificationGroup();
        $this->logModel = new NotificationLog();
    }

    public function index()
    {
        // Get current user and isolation mode
        $userId = \Core\Auth::id();
        $settingModel = new \App\Models\Setting();
        $isolationMode = $settingModel->getValue('user_isolation_mode', 'shared');
        
        // Get user-specific or global statistics
        if ($isolationMode === 'isolated') {
            $stats = $this->domainModel->getStatistics($userId);
            $recentDomains = $this->domainModel->getRecent(5, $userId);
            $groups = $this->groupModel->getAllWithChannelCount($userId);
        } else {
            $stats = $this->domainModel->getStatistics();
            $recentDomains = $this->domainModel->getRecent(5);
            $groups = $this->groupModel->getAllWithChannelCount();
        }
        
        // Get expiring threshold from settings
        $notificationDays = $settingModel->getNotificationDays();
        $expiringThreshold = !empty($notificationDays) ? max($notificationDays) : 30;
        
        // Get expiring domains limited to top 5
        if ($isolationMode === 'isolated') {
            $allExpiringDomains = $this->domainModel->getExpiringDomains($expiringThreshold, $userId);
        } else {
            $allExpiringDomains = $this->domainModel->getExpiringDomains($expiringThreshold);
        }
        $expiringThisMonth = array_slice($allExpiringDomains, 0, 5);
        
        $recentLogs = $this->logModel->getRecent(10);
        
        // Check system status
        $systemStatus = $this->checkSystemStatus();
        
        // Format domains for display
        $formattedRecentDomains = \App\Helpers\DomainHelper::formatMultiple($recentDomains);
        $formattedExpiringDomains = \App\Helpers\DomainHelper::formatMultiple($expiringThisMonth);
        
        // Get global stats for dashboard cards
        $globalStats = \App\Helpers\LayoutHelper::getGlobalStats($userId);

        $this->view('dashboard/index', [
            'stats' => $stats,
            'globalStats' => $globalStats,
            'recentDomains' => $formattedRecentDomains,
            'expiringThisMonth' => $formattedExpiringDomains,
            'expiringCount' => count($allExpiringDomains),
            'recentLogs' => $recentLogs,
            'groups' => $groups,
            'systemStatus' => $systemStatus,
            'title' => 'Dashboard'
        ]);
    }

    /**
     * Check system status
     */
    private function checkSystemStatus(): array
    {
        $status = [
            'database' => ['status' => 'offline', 'color' => 'red'],
            'whois' => ['status' => 'offline', 'color' => 'red'],
            'notifications' => ['status' => 'disabled', 'color' => 'gray']
        ];

        // Check database connection
        try {
            $pdo = \Core\Database::getConnection();
            $pdo->query("SELECT 1");
            $status['database'] = ['status' => 'online', 'color' => 'green'];
        } catch (\Exception $e) {
            $status['database'] = ['status' => 'offline', 'color' => 'red'];
        }

        // Check TLD Registry (WHOIS service)
        try {
            $tldModel = new \App\Models\TldRegistry();
            // Check if ANY TLDs exist in registry (not just id=1)
            $tldStats = $tldModel->getStatistics();
            if ($tldStats['total'] > 0) {
                $status['whois'] = ['status' => 'active', 'color' => 'green'];
            } else {
                $status['whois'] = ['status' => 'no data', 'color' => 'yellow'];
            }
        } catch (\Exception $e) {
            $status['whois'] = ['status' => 'error', 'color' => 'red'];
        }

        // Check if any notification groups have active channels
        try {
            $channelModel = new \App\Models\NotificationChannel();
            $activeChannels = $channelModel->where('is_active', 1);
            if (count($activeChannels) > 0) {
                $status['notifications'] = ['status' => 'enabled', 'color' => 'green'];
            } else {
                $status['notifications'] = ['status' => 'no channels', 'color' => 'yellow'];
            }
        } catch (\Exception $e) {
            $status['notifications'] = ['status' => 'error', 'color' => 'red'];
        }

        return $status;
    }
}

