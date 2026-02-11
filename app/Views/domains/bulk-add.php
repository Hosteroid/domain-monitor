<?php
$title = 'Bulk Add Domains';
$pageTitle = 'Bulk Add Domains';
$pageDescription = 'Add multiple domains at once';
$pageIcon = 'fas fa-layer-group';
ob_start();
?>

<!-- Main Container -->
<div class="max-w-4xl mx-auto">
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <!-- Tabs -->
        <div class="flex border-b border-gray-200 bg-gray-50">
            <button onclick="switchTab('paste')" id="tab-paste" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary bg-white transition-colors">
                <i class="fas fa-keyboard mr-2"></i>Paste Domains
            </button>
            <button onclick="switchTab('import')" id="tab-import" class="px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 transition-colors">
                <i class="fas fa-file-upload mr-2"></i>Import from File
            </button>
        </div>

        <!-- Tab 1: Paste Domains (existing) -->
        <div id="panel-paste" class="p-6">
            <form method="POST" action="/domains/bulk-add" class="space-y-5">
                <?= csrf_field() ?>
                <!-- Domains Textarea -->
                <div>
                    <label for="domains" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Domain Names *
                    </label>
                    <textarea 
                        id="domains" 
                        name="domains" 
                        rows="10"
                        class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm font-mono" 
                        placeholder="example.com&#10;google.com&#10;github.com&#10;..."
                        required
                        autofocus></textarea>
                    <p class="mt-1.5 text-xs text-gray-500">
                        Enter one domain per line. Domains without http:// or www.
                    </p>
                </div>

                <!-- Tags -->
                <div>
                    <label for="tags-input" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Tags
                        <span class="text-gray-400 font-normal">(Optional)</span>
                    </label>
                    
                    <div id="tags-display" class="min-h-[40px] p-2 border border-gray-300 rounded-lg mb-2 flex flex-wrap gap-1.5 bg-gray-50"></div>
                    
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
                    
                    <input type="hidden" id="tags" name="tags" value="">
                    
                    <p class="mt-1.5 text-xs text-gray-500">
                        <i class="fas fa-info-circle mr-1"></i>
                        All imported domains will be tagged with these tags.
                    </p>
                    
                    <div class="mt-2">
                        <p class="text-xs text-gray-600 mb-1.5">Available Tags:</p>
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
                        Notification Group (Optional)
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
                        Assign all domains to this notification group
                    </p>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row gap-3 pt-3">
                    <button type="submit" 
                            class="inline-flex items-center justify-center px-5 py-2.5 bg-primary hover:bg-primary-dark text-white rounded-lg font-medium transition-colors text-sm">
                        <i class="fas fa-plus-circle mr-2"></i>
                        Add All Domains
                    </button>
                    <a href="/domains" 
                       class="inline-flex items-center justify-center px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50 transition-colors text-sm">
                        <i class="fas fa-times mr-2"></i>
                        Cancel
                    </a>
                </div>
            </form>
        </div>

        <!-- Tab 2: Import from File -->
        <div id="panel-import" class="hidden p-6">
            <form method="POST" action="/domains/import" enctype="multipart/form-data" class="space-y-5" id="domainImportForm">
                <?= csrf_field() ?>
                
                <!-- Drag & Drop Zone -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">
                        Select File *
                    </label>
                    <div id="domainDropzone" class="relative border-2 border-dashed border-gray-300 rounded-lg p-8 text-center cursor-pointer transition-all hover:border-primary hover:bg-gray-50">
                        <input type="file" name="import_file" accept=".csv,.json" required id="domainFileInput"
                               class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                        <div id="domainDropzoneContent">
                            <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-3"></i>
                            <p class="text-sm text-gray-600 font-medium">Drag & drop your file here</p>
                            <p class="text-xs text-gray-400 my-1.5">or</p>
                            <span class="inline-flex items-center px-4 py-2 bg-primary text-white text-xs rounded-lg font-medium">
                                <i class="fas fa-folder-open mr-1.5"></i>Browse Files
                            </span>
                            <p class="mt-3 text-xs text-gray-400">CSV, JSON &middot; Max <?= \App\Helpers\ViewHelper::getMaxUploadSize() ?></p>
                        </div>
                        <div id="domainDropzoneFile" class="hidden">
                            <i class="fas fa-file-alt text-2xl text-primary mb-1.5"></i>
                            <p class="text-sm font-medium text-gray-700" id="domainFileName"></p>
                            <p class="text-xs text-gray-400" id="domainFileSize"></p>
                            <button type="button" id="domainFileRemove" class="mt-1.5 text-xs text-red-500 hover:text-red-700 font-medium">
                                <i class="fas fa-trash-alt mr-1"></i>Remove
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Expected Format Info -->
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <p class="text-sm font-medium text-gray-900 mb-2"><i class="fas fa-info-circle text-blue-500 mr-1.5"></i>Expected File Format</p>
                    <p class="text-xs text-gray-600 mb-2">CSV columns or JSON fields:</p>
                    <div class="flex flex-wrap gap-1.5">
                        <code class="px-2 py-0.5 bg-white rounded text-xs border border-blue-200 font-semibold text-blue-800">domain_name *</code>
                        <code class="px-2 py-0.5 bg-white rounded text-xs border border-gray-200 text-gray-600">tags</code>
                        <code class="px-2 py-0.5 bg-white rounded text-xs border border-gray-200 text-gray-600">notes</code>
                        <code class="px-2 py-0.5 bg-white rounded text-xs border border-gray-200 text-gray-600">notification_group</code>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">Only <code class="bg-white px-1 rounded">domain_name</code> is required. Tags should be comma-separated. Notification group is matched by name.</p>
                </div>

                <!-- Fallback Notification Group -->
                <div>
                    <label for="import_notification_group_id" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Default Notification Group
                        <span class="text-gray-400 font-normal">(for domains without a group in the file)</span>
                    </label>
                    <select id="import_notification_group_id" 
                            name="notification_group_id" 
                            class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors text-sm">
                        <option value="">-- No Group (No notifications) --</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?= $group['id'] ?>"><?= htmlspecialchars($group['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row gap-3 pt-3">
                    <button type="submit" id="domainImportBtn"
                            class="inline-flex items-center justify-center px-5 py-2.5 bg-primary hover:bg-primary-dark text-white rounded-lg font-medium transition-colors text-sm">
                        <i class="fas fa-file-import mr-2"></i>
                        Import Domains
                    </button>
                    <a href="/domains" 
                       class="inline-flex items-center justify-center px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50 transition-colors text-sm">
                        <i class="fas fa-times mr-2"></i>
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div><!-- end card -->

    <!-- Info Cards -->
    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
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
                        Paste domain names or upload a CSV/JSON file. The system will fetch WHOIS information 
                        for each domain automatically. This may take a few moments depending on how many domains you're adding.
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-orange-50 border border-orange-200 rounded-lg p-4">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <div class="w-10 h-10 bg-orange-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-exclamation-triangle text-white"></i>
                    </div>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-semibold text-gray-900 mb-1">Important Notes</h3>
                    <ul class="text-xs text-gray-600 space-y-1">
                        <li class="flex items-start">
                            <i class="fas fa-circle text-orange-500 mt-1 mr-2" style="font-size: 6px;"></i>
                            <span>Duplicate domains will be skipped</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-circle text-orange-500 mt-1 mr-2" style="font-size: 6px;"></i>
                            <span>Invalid domains will be reported</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-circle text-orange-500 mt-1 mr-2" style="font-size: 6px;"></i>
                            <span>Large batches may take several minutes</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Tab switching
function switchTab(tab) {
    document.getElementById('panel-paste').classList.toggle('hidden', tab !== 'paste');
    document.getElementById('panel-import').classList.toggle('hidden', tab !== 'import');
    
    const pasteTab = document.getElementById('tab-paste');
    const importTab = document.getElementById('tab-import');
    
    [pasteTab, importTab].forEach(btn => {
        btn.classList.remove('border-primary', 'text-primary', 'bg-white', 'border-transparent', 'text-gray-500');
    });
    const active = tab === 'paste' ? pasteTab : importTab;
    const inactive = tab === 'paste' ? importTab : pasteTab;
    active.classList.add('border-primary', 'text-primary', 'bg-white');
    inactive.classList.add('border-transparent', 'text-gray-500');
}

let tags = [];

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

// --- Domain Import drag-and-drop & loading ---
(function() {
    const dropzone = document.getElementById('domainDropzone');
    const fileInput = document.getElementById('domainFileInput');
    const content = document.getElementById('domainDropzoneContent');
    const fileInfo = document.getElementById('domainDropzoneFile');
    const fileName = document.getElementById('domainFileName');
    const fileSize = document.getElementById('domainFileSize');
    const removeBtn = document.getElementById('domainFileRemove');
    const form = document.getElementById('domainImportForm');
    const submitBtn = document.getElementById('domainImportBtn');

    function formatSize(bytes) {
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return bytes + ' B';
    }

    function showFile(file) {
        fileName.textContent = file.name;
        fileSize.textContent = formatSize(file.size);
        content.classList.add('hidden');
        fileInfo.classList.remove('hidden');
        dropzone.classList.remove('border-gray-300');
        dropzone.classList.add('border-primary', 'bg-primary/5');
    }

    function resetDropzone() {
        fileInput.value = '';
        content.classList.remove('hidden');
        fileInfo.classList.add('hidden');
        dropzone.classList.add('border-gray-300');
        dropzone.classList.remove('border-primary', 'bg-primary/5');
    }

    fileInput.addEventListener('change', function() {
        if (this.files.length) showFile(this.files[0]);
    });

    removeBtn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        resetDropzone();
    });

    ['dragenter', 'dragover'].forEach(evt => {
        dropzone.addEventListener(evt, function(e) {
            e.preventDefault();
            dropzone.classList.add('border-primary', 'bg-primary/5');
            dropzone.classList.remove('border-gray-300');
        });
    });

    ['dragleave', 'drop'].forEach(evt => {
        dropzone.addEventListener(evt, function(e) {
            e.preventDefault();
            if (!fileInput.files.length) {
                dropzone.classList.remove('border-primary', 'bg-primary/5');
                dropzone.classList.add('border-gray-300');
            }
        });
    });

    dropzone.addEventListener('drop', function(e) {
        e.preventDefault();
        const files = e.dataTransfer.files;
        if (files.length) {
            fileInput.files = files;
            showFile(files[0]);
        }
    });

    form.addEventListener('submit', function() {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Importing & Fetching WHOIS...';
    });
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>

