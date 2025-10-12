<?php
$title = 'Add New Domain';
$pageTitle = 'Add New Domain';
$pageDescription = 'Start monitoring a new domain';
$pageIcon = 'fas fa-plus-circle';
ob_start();
?>

<!-- Main Form -->
<div class="max-w-3xl mx-auto">
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                <i class="fas fa-globe text-gray-400 mr-2 text-sm"></i>
                Domain Information
            </h2>
        </div>
        
        <div class="p-6">
            <form method="POST" action="/domains/store" class="space-y-5">
                <?= csrf_field() ?>
                <!-- Domain Name -->
                <div>
                    <label for="domain_name" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Domain Name *
                    </label>
                    <input type="text" 
                           id="domain_name" 
                           name="domain_name" 
                           class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm" 
                           placeholder="example.com"
                           required
                           autofocus>
                    <p class="mt-1.5 text-xs text-gray-500">
                        Enter the domain name without http:// or https://
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
                    <input type="hidden" id="tags" name="tags" value="">
                    
                    <p class="mt-1.5 text-xs text-gray-500">
                        <i class="fas fa-info-circle mr-1"></i>
                        Type any custom tag (letters, numbers, hyphens). Press <kbd class="px-1 py-0.5 bg-gray-200 rounded text-xs">Enter</kbd> or <kbd class="px-1 py-0.5 bg-gray-200 rounded text-xs">,</kbd> to add.
                    </p>
                    
                    <!-- Suggested Tags -->
                    <div class="mt-2">
                        <p class="text-xs text-gray-600 mb-1.5">💡 Suggestions:</p>
                        <div class="flex flex-wrap gap-1.5">
                            <button type="button" onclick="addTag('production')" class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium border bg-green-50 text-green-700 border-green-200 hover:bg-green-100 transition-colors">
                                <i class="fas fa-plus mr-1" style="font-size: 8px;"></i>
                                Production
                            </button>
                            <button type="button" onclick="addTag('staging')" class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium border bg-yellow-50 text-yellow-700 border-yellow-200 hover:bg-yellow-100 transition-colors">
                                <i class="fas fa-plus mr-1" style="font-size: 8px;"></i>
                                Staging
                            </button>
                            <button type="button" onclick="addTag('development')" class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium border bg-blue-50 text-blue-700 border-blue-200 hover:bg-blue-100 transition-colors">
                                <i class="fas fa-plus mr-1" style="font-size: 8px;"></i>
                                Development
                            </button>
                            <button type="button" onclick="addTag('client')" class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium border bg-purple-50 text-purple-700 border-purple-200 hover:bg-purple-100 transition-colors">
                                <i class="fas fa-plus mr-1" style="font-size: 8px;"></i>
                                Client
                            </button>
                            <button type="button" onclick="addTag('personal')" class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium border bg-orange-50 text-orange-700 border-orange-200 hover:bg-orange-100 transition-colors">
                                <i class="fas fa-plus mr-1" style="font-size: 8px;"></i>
                                Personal
                            </button>
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
                            <option value="<?= $group['id'] ?>"><?= htmlspecialchars($group['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-1.5 text-xs text-gray-500">
                        Optional: Assign to a notification group to receive expiry alerts
                    </p>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row gap-3 pt-3">
                    <button type="submit" 
                            class="inline-flex items-center justify-center px-5 py-2.5 bg-primary hover:bg-primary-dark text-white rounded-lg font-medium transition-colors text-sm">
                        <i class="fas fa-plus-circle mr-2"></i>
                        Add Domain
                    </button>
                    <a href="/domains" 
                       class="inline-flex items-center justify-center px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50 transition-colors text-sm">
                        <i class="fas fa-times mr-2"></i>
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Info Cards -->
    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
        <!-- How it works -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-info-circle text-white"></i>
                    </div>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-semibold text-gray-900 mb-1">How It Works</h3>
                    <p class="text-xs text-gray-600 leading-relaxed">
                        When you add a domain, we automatically fetch its WHOIS information including 
                        expiration date, registrar, nameservers, and other important details. This may take a few seconds.
                    </p>
                </div>
            </div>
        </div>

        <!-- What we track -->
        <div class="bg-green-50 border border-green-200 rounded-lg p-4">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <div class="w-10 h-10 bg-green-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-check-circle text-white"></i>
                    </div>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-semibold text-gray-900 mb-1">What We Track</h3>
                    <ul class="text-xs text-gray-600 space-y-1">
                        <li class="flex items-center">
                            <i class="fas fa-circle text-green-500" style="font-size: 6px;"></i>
                            <span class="ml-2">Domain expiration date</span>
                        </li>
                        <li class="flex items-center">
                            <i class="fas fa-circle text-green-500" style="font-size: 6px;"></i>
                            <span class="ml-2">Registrar information</span>
                        </li>
                        <li class="flex items-center">
                            <i class="fas fa-circle text-green-500" style="font-size: 6px;"></i>
                            <span class="ml-2">Nameservers</span>
                        </li>
                        <li class="flex items-center">
                            <i class="fas fa-circle text-green-500" style="font-size: 6px;"></i>
                            <span class="ml-2">Domain status</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let tags = [];

const tagColors = {
    'production': 'bg-green-100 text-green-700 border-green-300',
    'staging': 'bg-yellow-100 text-yellow-700 border-yellow-300',
    'development': 'bg-blue-100 text-blue-700 border-blue-300',
    'client': 'bg-purple-100 text-purple-700 border-purple-300',
    'personal': 'bg-orange-100 text-orange-700 border-orange-300',
    'archived': 'bg-gray-100 text-gray-700 border-gray-300'
};

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
