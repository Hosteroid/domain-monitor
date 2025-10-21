<?php
$title = 'Edit Notification Group';
$pageTitle = 'Edit Notification Group';
$pageDescription = htmlspecialchars($group['name']);
$pageIcon = 'fas fa-edit';
ob_start();
?>

<div class="max-w-7xl mx-auto space-y-4">
    <!-- Group Details Form -->
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                <i class="fas fa-info-circle text-gray-400 mr-2 text-sm"></i>
                Group Details
            </h2>
        </div>
        
        <div class="p-6">
            <form method="POST" action="/groups/<?= $group['id'] ?>/update" class="space-y-5">
                <?= csrf_field() ?>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <!-- Group Name -->
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-1.5">
                            Group Name *
                        </label>
                        <input type="text" 
                               id="name" 
                               name="name" 
                               class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm" 
                               value="<?= htmlspecialchars($group['name']) ?>"
                               required>
                    </div>

                    <!-- Description -->
                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-1.5">
                            Description (Optional)
                        </label>
                        <textarea id="description" 
                                  name="description" 
                                  class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm" 
                                  rows="3"><?= htmlspecialchars($group['description'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="flex gap-3">
                    <button type="submit" 
                            class="inline-flex items-center px-5 py-2.5 bg-primary hover:bg-primary-dark text-white rounded-lg font-medium transition-colors text-sm">
                        <i class="fas fa-save mr-2"></i>
                        Update Group
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Notification Channels -->
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                <i class="fas fa-plug text-gray-400 mr-2 text-sm"></i>
                Notification Channels
            </h2>
        </div>
        
        <div class="p-6">
            <?php if (empty($group['channels'])): ?>
                <div class="text-center py-10">
                    <i class="fas fa-plug text-gray-300 text-5xl mb-3"></i>
                    <p class="text-gray-500">No channels configured yet</p>
                    <p class="text-sm text-gray-400 mt-1">Add your first channel below to start receiving notifications</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
                    <?php foreach ($group['channels'] as $channel): 
                        $config = json_decode($channel['channel_config'], true);
                        $icons = ['email' => 'fa-envelope', 'telegram' => 'fa-telegram', 'discord' => 'fa-discord', 'slack' => 'fa-slack', 'mattermost' => 'fa-comments', 'webhook' => 'fa-link'];
                        $iconClasses = ['email' => 'fas', 'telegram' => 'fab', 'discord' => 'fab', 'slack' => 'fab', 'mattermost' => 'fab', 'webhook' => 'fas'];
                        $colors = ['email' => 'blue', 'telegram' => 'blue', 'discord' => 'indigo', 'slack' => 'teal', 'mattermost' => 'green', 'webhook' => 'purple'];
                        $icon = $icons[$channel['channel_type']] ?? 'fa-bell';
                        $iconClass = $iconClasses[$channel['channel_type']] ?? 'fas';
                        $color = $colors[$channel['channel_type']] ?? 'gray';
                    ?>
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-6 hover:shadow-md transition-shadow duration-200">
                            <div class="flex items-start justify-between mb-4">
                                <div class="w-12 h-12 bg-<?= $color ?>-100 rounded-lg flex items-center justify-center">
                                    <i class="<?= $iconClass ?> <?= $icon ?> text-<?= $color ?>-600 text-xl"></i>
                                </div>
                                <span class="px-3 py-1 rounded-full text-xs font-semibold <?= $channel['is_active'] ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-600' ?>">
                                    <?= $channel['is_active'] ? 'Active' : 'Disabled' ?>
                                </span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-2"><?= ucfirst($channel['channel_type']) ?></h3>
                            <p class="text-sm text-gray-600 mb-4 truncate">
                                <?php
                                if ($channel['channel_type'] === 'email') {
                                    echo htmlspecialchars($config['email'] ?? 'No email');
                                } elseif ($channel['channel_type'] === 'telegram') {
                                    echo "Chat: " . htmlspecialchars($config['chat_id'] ?? 'N/A');
                                } else {
                                    echo "Webhook configured";
                                }
                                ?>
                            </p>
                            <div class="flex gap-2">
                                <button onclick="testChannel('<?= $channel['channel_type'] ?>', <?= htmlspecialchars(json_encode($config)) ?>)" 
                                        class="flex-1 px-3 py-2 bg-blue-50 text-blue-700 rounded text-center text-sm hover:bg-blue-100 transition-colors duration-150">
                                    <i class="fas fa-paper-plane mr-1"></i>
                                    Test
                                </button>
                                <form method="POST" action="/groups/<?= $group['id'] ?>/channels/<?= $channel['id'] ?>/toggle" class="flex-1">
                                    <?= csrf_field() ?>
                                    <button type="submit" 
                                            class="w-full px-3 py-2 bg-yellow-50 text-yellow-700 rounded text-center text-sm hover:bg-yellow-100 transition-colors duration-150">
                                        <i class="fas fa-<?= $channel['is_active'] ? 'pause' : 'play' ?> mr-1"></i>
                                        <?= $channel['is_active'] ? 'Disable' : 'Enable' ?>
                                    </button>
                                </form>
                                <form method="POST" action="/groups/<?= $group['id'] ?>/channels/<?= $channel['id'] ?>/delete" class="flex-1">
                                    <?= csrf_field() ?>
                                    <button type="submit" 
                                            class="w-full px-3 py-2 bg-red-50 text-red-700 rounded text-center text-sm hover:bg-red-100 transition-colors duration-150"
                                            onclick="return confirm('Delete this channel?')">
                                        <i class="fas fa-trash mr-1"></i>
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Add Channel Form -->
            <div class="bg-gray-50 rounded-lg p-5 border border-gray-200">
                <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center">
                    <i class="fas fa-plus-circle text-gray-400 mr-2 text-sm"></i>
                    Add New Channel
                </h3>

                <form method="POST" action="/groups/<?= $group['id'] ?>/channels" id="channelForm" class="space-y-5">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group_id" value="<?= $group['id'] ?>">

                    <!-- Channel Type -->
                    <div>
                        <label for="channel_type" class="block text-sm font-medium text-gray-700 mb-1.5">Channel Type</label>
                        <select id="channel_type" 
                                name="channel_type" 
                                class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm" 
                                onchange="toggleChannelFields()">
                            <option value="">-- Select Channel Type --</option>
                            <option value="email">Email</option>
                            <option value="telegram">Telegram</option>
                            <option value="discord">Discord</option>
                            <option value="slack">Slack</option>
                            <option value="mattermost">Mattermost</option>
                            <option value="webhook">Webhook (Custom)</option>
                        </select>
                    </div>

                    <!-- Email Fields -->
                    <div id="email_fields" class="hidden space-y-4">
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 mb-1.5">
                                Email Address
                            </label>
                            <input type="email" 
                                   id="email" 
                                   name="email" 
                                   class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm" 
                                   placeholder="user@example.com">
                        </div>
                    </div>

                    <!-- Telegram Fields -->
                    <div id="telegram_fields" class="hidden space-y-4">
                        <div>
                            <label for="bot_token" class="block text-sm font-medium text-gray-700 mb-1.5">
                                Bot Token
                            </label>
                            <input type="text" 
                                   id="bot_token" 
                                   name="bot_token" 
                                   class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm" 
                                   placeholder="123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11">
                            <p class="mt-1.5 text-xs text-gray-500">
                                Get from @BotFather on Telegram
                            </p>
                        </div>
                        <div>
                            <label for="chat_id" class="block text-sm font-medium text-gray-700 mb-1.5">
                                Chat ID
                            </label>
                            <input type="text" 
                                   id="chat_id" 
                                   name="chat_id" 
                                   class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm" 
                                   placeholder="123456789">
                            <p class="mt-1.5 text-xs text-gray-500">
                                Use @userinfobot to get your chat ID
                            </p>
                        </div>
                    </div>

                    <!-- Discord Fields -->
                    <div id="discord_fields" class="hidden space-y-4">
                        <div>
                            <label for="discord_webhook" class="block text-sm font-medium text-gray-700 mb-1.5">
                                Webhook URL <span class="text-red-500">*</span>
                            </label>
                            <input type="text" 
                                   id="discord_webhook" 
                                   name="discord_webhook_url" 
                                   class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm font-mono" 
                                   placeholder="https://discord.com/api/webhooks/1234567890/abcdefg..."
                                   autocomplete="off">
                            <p class="mt-1.5 text-xs text-gray-500">
                                <i class="fas fa-info-circle text-blue-500 mr-1"></i>
                                Paste the complete webhook URL from Discord Server Settings → Integrations → Webhooks
                            </p>
                        </div>
                    </div>

                    <!-- Slack Fields -->
                    <div id="slack_fields" class="hidden space-y-4">
                        <div>
                            <label for="slack_webhook" class="block text-sm font-medium text-gray-700 mb-1.5">
                                Webhook URL
                            </label>
                            <input type="text" 
                                   id="slack_webhook" 
                                   name="slack_webhook_url" 
                                   class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm font-mono" 
                                   placeholder="https://hooks.slack.com/services/..."
                                   autocomplete="off">
                            <p class="mt-1.5 text-xs text-gray-500">
                                Create in Slack App Settings → Incoming Webhooks
                            </p>
                        </div>
                    </div>

                    <!-- Mattermost Fields -->
                    <div id="mattermost_fields" class="hidden space-y-4">
                        <div>
                            <label for="mattermost_webhook" class="block text-sm font-medium text-gray-700 mb-1.5">
                                Webhook URL
                            </label>
                            <input type="text" 
                                   id="mattermost_webhook" 
                                   name="mattermost_webhook_url" 
                                   class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm font-mono" 
                                   placeholder="https://your-mattermost.com/hooks/..."
                                   autocomplete="off">
                            <p class="mt-1.5 text-xs text-gray-500">
                                Create in Mattermost → Integrations → Incoming Webhooks
                            </p>
                        </div>
                    </div>

                    <!-- Generic Webhook Fields -->
                    <div id="webhook_fields" class="hidden space-y-4">
                        <div>
                            <label for="generic_webhook_url" class="block text-sm font-medium text-gray-700 mb-1.5">
                                Webhook URL
                            </label>
                            <input type="text" 
                                   id="generic_webhook_url" 
                                   name="webhook_url" 
                                   class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm font-mono" 
                                   placeholder="https://example.com/webhook-endpoint"
                                   autocomplete="off">
                            <p class="mt-1.5 text-xs text-gray-500">
                                Will receive JSON payload compatible with n8n/Zapier/Make.
                            </p>
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <button type="submit" 
                                class="inline-flex items-center px-5 py-2.5 bg-primary hover:bg-primary-dark text-white rounded-lg font-medium transition-colors text-sm">
                            <i class="fas fa-plus mr-2"></i>
                            Add Channel
                        </button>
                        
                        <button type="button" 
                                id="testChannelBtn"
                                onclick="testChannel()"
                                class="inline-flex items-center px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors text-sm hidden">
                            <i class="fas fa-paper-plane mr-2"></i>
                            Test Channel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Assigned Domains -->
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                <i class="fas fa-globe text-gray-400 mr-2 text-sm"></i>
                Assigned Domains (<?= count($group['domains']) ?>)
            </h2>
        </div>
        
        <div class="p-6">
            <?php if (empty($group['domains'])): ?>
                <div class="text-center py-10">
                    <i class="fas fa-globe text-gray-300 text-5xl mb-3"></i>
                    <p class="text-gray-500">No domains assigned to this group yet</p>
                    <a href="/domains/create" class="mt-3 inline-flex items-center px-5 py-2.5 bg-primary text-white text-sm rounded-lg hover:bg-primary-dark transition-colors font-medium">
                        <i class="fas fa-plus mr-2"></i>
                        Add a Domain
                    </a>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($group['domains'] as $domain): ?>
                        <a href="/domains/<?= $domain['id'] ?>" class="block bg-gray-50 border border-gray-200 rounded-lg p-6 hover:shadow-md hover:border-primary transition-all duration-200">
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-primary bg-opacity-10 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-globe text-primary text-xl"></i>
                                </div>
                                <?php
                                $statusClass = $domain['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-600';
                                ?>
                                <span class="px-3 py-1 rounded-full text-xs font-semibold <?= $statusClass ?>">
                                    <?= ucfirst($domain['status']) ?>
                                </span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-2 truncate"><?= htmlspecialchars($domain['domain_name']) ?></h3>
                            <p class="text-sm text-gray-600 flex items-center">
                                <i class="far fa-calendar mr-2"></i>
                                Expires: <?= date('M j, Y', strtotime($domain['expiration_date'])) ?>
                            </p>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleChannelFields() {
    const channelType = document.getElementById('channel_type').value;
    const testBtn = document.getElementById('testChannelBtn');
    
    // Get all input fields
    const emailField = document.getElementById('email');
    const botTokenField = document.getElementById('bot_token');
    const chatIdField = document.getElementById('chat_id');
    const discordWebhook = document.getElementById('discord_webhook');
    const slackWebhook = document.getElementById('slack_webhook');
    const mattermostWebhook = document.getElementById('mattermost_webhook');
    const genericWebhook = document.getElementById('generic_webhook_url');
    
    // Remove required from all
    emailField.removeAttribute('required');
    botTokenField.removeAttribute('required');
    chatIdField.removeAttribute('required');
    discordWebhook.removeAttribute('required');
    slackWebhook.removeAttribute('required');
    if (mattermostWebhook) mattermostWebhook.removeAttribute('required');
    if (genericWebhook) genericWebhook.removeAttribute('required');
    
    // Hide all fields
    document.getElementById('email_fields').classList.add('hidden');
    document.getElementById('telegram_fields').classList.add('hidden');
    document.getElementById('discord_fields').classList.add('hidden');
    document.getElementById('slack_fields').classList.add('hidden');
    document.getElementById('mattermost_fields').classList.add('hidden');
    document.getElementById('webhook_fields').classList.add('hidden');
    
    // Hide test button by default
    testBtn.classList.add('hidden');
    
    // Show selected field and make required
    if (channelType) {
        document.getElementById(channelType + '_fields').classList.remove('hidden');
        
        // Set required based on type
        switch(channelType) {
            case 'email':
                emailField.setAttribute('required', 'required');
                break;
            case 'telegram':
                botTokenField.setAttribute('required', 'required');
                chatIdField.setAttribute('required', 'required');
                break;
            case 'discord':
                discordWebhook.setAttribute('required', 'required');
                discordWebhook.focus(); // Auto-focus for easy paste
                break;
            case 'slack':
                slackWebhook.setAttribute('required', 'required');
                slackWebhook.focus();
                break;
            case 'mattermost':
                if (mattermostWebhook) {
                    mattermostWebhook.setAttribute('required', 'required');
                    mattermostWebhook.focus();
                }
                break;
            case 'webhook':
                if (genericWebhook) {
                    genericWebhook.setAttribute('required', 'required');
                    genericWebhook.focus();
                }
                break;
        }
        
        // Show test button when channel type is selected
        testBtn.classList.remove('hidden');
    }
}

// Form validation before submit
const addChannelForm = document.querySelector('form[action="/groups/<?= $group['id'] ?>/channels"]');
if (addChannelForm) {
    addChannelForm.addEventListener('submit', function(e) {
    const channelType = document.getElementById('channel_type').value;
    
    if (!channelType) {
        e.preventDefault();
        alert('Please select a channel type');
        return false;
    }
    
    // Validate Discord webhook
    if (channelType === 'discord') {
        const webhookField = document.getElementById('discord_webhook');
        const webhookUrl = webhookField.value.trim();
        
        if (!webhookUrl) {
            e.preventDefault();
            alert('Please enter the Discord webhook URL');
            webhookField.focus();
            return false;
        }
        if (!webhookUrl.includes('discord.com/api/webhooks/')) {
            e.preventDefault();
            alert('Invalid Discord webhook URL. It should start with:\nhttps://discord.com/api/webhooks/');
            webhookField.focus();
            return false;
        }
    }
    
    // Validate Slack webhook
    if (channelType === 'slack') {
        const webhookUrl = document.getElementById('slack_webhook').value.trim();
        if (!webhookUrl) {
            e.preventDefault();
            alert('Please enter the Slack webhook URL');
            document.getElementById('slack_webhook').focus();
            return false;
        }
    }

    // Validate Mattermost webhook
    if (channelType === 'mattermost') {
        const webhookUrl = document.getElementById('mattermost_webhook').value.trim();
        if (!webhookUrl) {
            e.preventDefault();
            alert('Please enter the Mattermost webhook URL');
            document.getElementById('mattermost_webhook').focus();
            return false;
        }
    }

    // Validate Generic webhook
    if (channelType === 'webhook') {
        const webhookUrl = document.getElementById('generic_webhook_url').value.trim();
        if (!webhookUrl) {
            e.preventDefault();
            alert('Please enter the Webhook URL');
            document.getElementById('generic_webhook_url').focus();
            return false;
        }
    }
    
    return true;
    });
}

// Test channel functionality - handles both new and existing channels
function testChannel(channelType, existingConfig = null) {
    // If existingConfig is provided, we're testing an existing channel
    // If not, we're testing a new channel from the form
    const isExistingChannel = existingConfig !== null;
    
    if (!isExistingChannel) {
        // For new channels, get values from form
        channelType = document.getElementById('channel_type').value;
        const testBtn = document.getElementById('testChannelBtn');
        
        if (!channelType) {
            alert('Please select a channel type first');
            return;
        }
        
        // Validate required fields before testing
        let isValid = true;
        let errorMessage = '';
        
        switch(channelType) {
            case 'email':
                const email = document.getElementById('email').value.trim();
                if (!email) {
                    isValid = false;
                    errorMessage = 'Please enter an email address';
                } else if (!email.includes('@') || !email.includes('.')) {
                    isValid = false;
                    errorMessage = 'Please enter a valid email address';
                }
                break;
                
            case 'telegram':
                const botToken = document.getElementById('bot_token').value.trim();
                const chatId = document.getElementById('chat_id').value.trim();
                if (!botToken) {
                    isValid = false;
                    errorMessage = 'Please enter a bot token';
                } else if (!/^\d+:[A-Za-z0-9_-]+$/.test(botToken)) {
                    isValid = false;
                    errorMessage = 'Please enter a valid bot token format (e.g., 123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11)';
                } else if (!chatId) {
                    isValid = false;
                    errorMessage = 'Please enter a chat ID';
                } else if (!/^-?\d+$/.test(chatId)) {
                    isValid = false;
                    errorMessage = 'Please enter a valid chat ID (numeric value)';
                }
                break;
                
            case 'discord':
                const discordWebhook = document.getElementById('discord_webhook').value.trim();
                if (!discordWebhook) {
                    isValid = false;
                    errorMessage = 'Please enter a Discord webhook URL';
                } else if (!discordWebhook.includes('discord.com/api/webhooks/')) {
                    isValid = false;
                    errorMessage = 'Invalid Discord webhook URL';
                }
                break;
                
            case 'slack':
                const slackWebhook = document.getElementById('slack_webhook').value.trim();
                if (!slackWebhook) {
                    isValid = false;
                    errorMessage = 'Please enter a Slack webhook URL';
                }
                break;
            case 'mattermost':
                const mattermostWebhook = document.getElementById('mattermost_webhook').value.trim();
                if (!mattermostWebhook) {
                    isValid = false;
                    errorMessage = 'Please enter a Mattermost webhook URL';
                }
                break;
            case 'webhook':
                const genericWebhook = document.getElementById('generic_webhook_url').value.trim();
                if (!genericWebhook) {
                    isValid = false;
                    errorMessage = 'Please enter a Webhook URL';
                }
                break;
        }
        
        if (!isValid) {
            alert(errorMessage);
            return;
        }
        
        // Disable button and show loading state for new channels
        testBtn.disabled = true;
        testBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Testing...';
    }
    
    // Create form data for AJAX request
    const formData = new FormData();
    formData.append('channel_type', channelType);
    
    // Add group ID from URL or form
    let groupId = document.querySelector('input[name="group_id"]')?.value;
    if (!groupId) {
        // Extract group ID from URL if not in form
        const urlParts = window.location.pathname.split('/');
        groupId = urlParts[urlParts.indexOf('groups') + 1];
    }
    formData.append('group_id', groupId);
    
    // Add CSRF token
    const csrfToken = document.querySelector('input[name="csrf_token"]').value;
    formData.append('csrf_token', csrfToken);
    
    // Add channel-specific data
    if (isExistingChannel) {
        // Use existing channel config
        switch(channelType) {
            case 'email':
                formData.append('email', existingConfig.email);
                break;
            case 'telegram':
                formData.append('bot_token', existingConfig.bot_token);
                formData.append('chat_id', existingConfig.chat_id);
                break;
            case 'discord':
                formData.append('discord_webhook_url', existingConfig.webhook_url);
                break;
            case 'slack':
                formData.append('slack_webhook_url', existingConfig.webhook_url);
                break;
            case 'mattermost':
                formData.append('mattermost_webhook_url', existingConfig.webhook_url);
                break;
            case 'webhook':
                formData.append('webhook_url', existingConfig.webhook_url);
                break;
        }
    } else {
        // Use form values for new channels
        switch(channelType) {
            case 'email':
                formData.append('email', document.getElementById('email').value);
                break;
            case 'telegram':
                formData.append('bot_token', document.getElementById('bot_token').value);
                formData.append('chat_id', document.getElementById('chat_id').value);
                break;
            case 'discord':
                formData.append('discord_webhook_url', document.getElementById('discord_webhook').value);
                break;
            case 'slack':
                formData.append('slack_webhook_url', document.getElementById('slack_webhook').value);
                break;
            case 'mattermost':
                formData.append('mattermost_webhook_url', document.getElementById('mattermost_webhook').value);
                break;
            case 'webhook':
                formData.append('webhook_url', document.getElementById('generic_webhook_url').value);
                break;
        }
    }
    
    // Send AJAX request
    fetch('/channels/test', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        // Reset button for new channels
        if (!isExistingChannel) {
            const testBtn = document.getElementById('testChannelBtn');
            testBtn.disabled = false;
            testBtn.innerHTML = '<i class="fas fa-paper-plane mr-2"></i>Test Channel';
        }
        
        if (data.success) {
            showToast(data.message, 'success');
        } else {
            showToast(data.message, 'error');
        }
    })
    .catch(error => {
        // Reset button for new channels
        if (!isExistingChannel) {
            const testBtn = document.getElementById('testChannelBtn');
            testBtn.disabled = false;
            testBtn.innerHTML = '<i class="fas fa-paper-plane mr-2"></i>Test Channel';
        }
        
        showToast('❌ Test failed: ' + error.message + ' Please check your configuration and try again.', 'error');
    });
}


// Function to show toast messages dynamically
function showToast(message, type = 'info') {
    const toastContainer = document.getElementById('toast-container');
    if (!toastContainer) return;
    
    const typeConfig = {
        success: {
            icon: 'fa-check',
            iconColor: 'text-green-600',
            bgColor: 'bg-green-100',
            borderColor: 'border-green-500',
            title: 'Success'
        },
        error: {
            icon: 'fa-times',
            iconColor: 'text-red-600',
            bgColor: 'bg-red-100',
            borderColor: 'border-red-500',
            title: 'Error'
        },
        warning: {
            icon: 'fa-exclamation-triangle',
            iconColor: 'text-orange-600',
            bgColor: 'bg-orange-100',
            borderColor: 'border-orange-500',
            title: 'Warning'
        },
        info: {
            icon: 'fa-info',
            iconColor: 'text-blue-600',
            bgColor: 'bg-blue-100',
            borderColor: 'border-blue-500',
            title: 'Info'
        }
    };
    
    const config = typeConfig[type] || typeConfig.info;
    
    const toast = document.createElement('div');
    toast.className = `toast bg-white border-l-4 ${config.borderColor} rounded-lg shadow-lg p-4 flex items-start animate-slide-in`;
    toast.innerHTML = `
        <div class="flex-shrink-0">
            <div class="w-8 h-8 ${config.bgColor} rounded-full flex items-center justify-center">
                <i class="fas ${config.icon} ${config.iconColor} text-sm"></i>
            </div>
        </div>
        <div class="ml-3 flex-1">
            <p class="text-sm font-medium text-gray-900">${config.title}</p>
            <p class="text-sm text-gray-600 mt-0.5">${message}</p>
        </div>
        <button onclick="this.parentElement.remove()" class="ml-3 flex-shrink-0 text-gray-400 hover:text-gray-600 transition-colors">
            <i class="fas fa-times text-sm"></i>
        </button>
    `;
    
    toastContainer.appendChild(toast);
    
    // Auto-dismiss after 5 seconds
    setTimeout(() => {
        toast.style.transition = 'opacity 0.3s ease-out, transform 0.3s ease-out';
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        
        setTimeout(() => {
            toast.remove();
        }, 300);
    }, 5000);
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>
