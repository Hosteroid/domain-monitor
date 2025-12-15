<?php

namespace App\Controllers;

use Core\Controller;
use Core\Auth;
use App\Models\User;

class UserController extends Controller
{
    private User $userModel;

    public function __construct()
    {
        Auth::requireAdmin();
        $this->userModel = new User();
    }

    /**
     * List all users
     */
    public function index()
    {
        // Get filter parameters
        $search = \App\Helpers\InputValidator::sanitizeSearch($_GET['search'] ?? '', 100);
        $roleFilter = $_GET['role'] ?? '';
        $statusFilter = $_GET['status'] ?? '';
        $sort = $_GET['sort'] ?? 'username';
        $order = $_GET['order'] ?? 'asc';
        $perPage = (int)($_GET['per_page'] ?? 25);
        $page = max(1, (int)($_GET['page'] ?? 1));
        
        // Build filters array
        $filters = [
            'search' => $search,
            'role' => $roleFilter,
            'status' => $statusFilter
        ];
        
        // Count total records
        $totalRecords = $this->userModel->countFiltered($filters);
        
        // Calculate pagination
        $totalPages = ceil($totalRecords / $perPage);
        $page = min($page, max(1, $totalPages)); // Ensure page is within bounds
        $offset = ($page - 1) * $perPage;
        $showingFrom = $totalRecords > 0 ? $offset + 1 : 0;
        $showingTo = min($offset + $perPage, $totalRecords);
        
        // Get filtered users
        $users = $this->userModel->getFiltered($filters, $sort, strtoupper($order), $perPage, $offset);
        
        $this->view('users/index', [
            'users' => $users,
            'title' => 'User Management',
            'filters' => [
                'search' => $search,
                'role' => $roleFilter,
                'status' => $statusFilter,
                'sort' => $sort,
                'order' => strtolower($order)
            ],
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'per_page' => $perPage,
                'total' => $totalRecords,
                'showing_from' => $showingFrom,
                'showing_to' => $showingTo
            ]
        ]);
    }

    /**
     * Show create user form
     */
    public function create()
    {
        $this->view('users/create', [
            'title' => 'Create User'
        ]);
    }

    /**
     * Store new user
     */
    public function store()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/users');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/users/create');

        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        $role = $_POST['role'] ?? 'user';

        // Validation
        if (empty($username) || empty($email) || empty($fullName) || empty($password)) {
            $_SESSION['error'] = 'All fields are required';
            $this->redirect('/users/create');
            return;
        }

        // Validate username format and length
        $usernameError = \App\Helpers\InputValidator::validateUsername($username, 3, 50);
        if ($usernameError) {
            $_SESSION['error'] = $usernameError;
            $this->redirect('/users/create');
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Invalid email address';
            $this->redirect('/users/create');
            return;
        }

        // Validate full name length
        $nameError = \App\Helpers\InputValidator::validateLength($fullName, 255, 'Full name');
        if ($nameError) {
            $_SESSION['error'] = $nameError;
            $this->redirect('/users/create');
            return;
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $_SESSION['error'] = 'Username can only contain letters, numbers, and underscores';
            $this->redirect('/users/create');
            return;
        }

        if (strlen($password) < 8) {
            $_SESSION['error'] = 'Password must be at least 8 characters';
            $this->redirect('/users/create');
            return;
        }

        if ($password !== $passwordConfirm) {
            $_SESSION['error'] = 'Passwords do not match';
            $this->redirect('/users/create');
            return;
        }

        // Check if username exists
        if ($this->userModel->findByUsername($username)) {
            $_SESSION['error'] = 'Username already exists';
            $this->redirect('/users/create');
            return;
        }

        // Check if email exists
        if (!empty($this->userModel->where('email', $email))) {
            $_SESSION['error'] = 'Email already exists';
            $this->redirect('/users/create');
            return;
        }

        try {
            $userId = $this->userModel->createUser($username, $password, $email, $fullName);
            
            // Update role if not default
            if ($role !== 'user') {
                $this->userModel->update($userId, ['role' => $role]);
            }

            // Mark as verified by default (admin created)
            $this->userModel->update($userId, ['email_verified' => 1]);
            
            // Create welcome notification
            try {
                $notificationService = new \App\Services\NotificationService();
                $notificationService->notifyWelcome($userId, $username);
            } catch (\Exception $e) {
                // Don't fail user creation if notification fails
                $logger = new \App\Services\Logger();
                $logger->error("Failed to create welcome notification", [
                    'user_id' => $userId,
                    'error' => $e->getMessage()
                ]);
            }

            $_SESSION['success'] = 'User created successfully';
            $this->redirect('/users');

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to create user: ' . $e->getMessage();
            $this->redirect('/users/create');
        }
    }

    /**
     * Show edit user form
     */
    public function edit($params = [])
    {
        $userId = $params['id'] ?? 0;
        $user = $this->userModel->find($userId);

        if (!$user) {
            $_SESSION['error'] = 'User not found';
            $this->redirect('/users');
            return;
        }

        $this->view('users/edit', [
            'user' => $user,
            'title' => 'Edit User'
        ]);
    }

    /**
     * Update user
     */
    public function update($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/users');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/users');

        $userId = $params['id'] ?? 0;
        $user = $this->userModel->find($userId);

        if (!$user) {
            $_SESSION['error'] = 'User not found';
            $this->redirect('/users');
            return;
        }

        $email = trim($_POST['email'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $role = $_POST['role'] ?? 'user';
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $password = $_POST['password'] ?? '';

        // Validation
        if (empty($email) || empty($fullName)) {
            $_SESSION['error'] = 'Email and full name are required';
            $this->redirect("/users/$userId/edit");
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Invalid email address';
            $this->redirect("/users/$userId/edit");
            return;
        }

        // Validate full name length
        $nameError = \App\Helpers\InputValidator::validateLength($fullName, 255, 'Full name');
        if ($nameError) {
            $_SESSION['error'] = $nameError;
            $this->redirect("/users/$userId/edit");
            return;
        }

        // Check if email is taken by another user
        $existingUsers = $this->userModel->where('email', $email);
        if (!empty($existingUsers) && $existingUsers[0]['id'] != $userId) {
            $_SESSION['error'] = 'Email already in use by another user';
            $this->redirect("/users/$userId/edit");
            return;
        }

        try {
            $updateData = [
                'email' => $email,
                'full_name' => $fullName,
                'role' => $role,
                'is_active' => $isActive
            ];

            $this->userModel->update($userId, $updateData);

            // Update password if provided
            if (!empty($password)) {
                if (strlen($password) < 8) {
                    $_SESSION['error'] = 'Password must be at least 8 characters';
                    $this->redirect("/users/$userId/edit");
                    return;
                }
                $this->userModel->changePassword($userId, $password);
            }

            $_SESSION['success'] = 'User updated successfully';
            $this->redirect('/users');

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to update user: ' . $e->getMessage();
            $this->redirect("/users/$userId/edit");
        }
    }

    /**
     * Delete user
     */
    public function delete($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/users');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/users');

        $userId = $params['id'] ?? 0;
        $user = $this->userModel->find($userId);

        if (!$user) {
            $_SESSION['error'] = 'User not found';
            $this->redirect('/users');
            return;
        }

        // Prevent deleting yourself
        if ($userId == Auth::id()) {
            $_SESSION['error'] = 'You cannot delete your own account';
            $this->redirect('/users');
            return;
        }

        // Prevent deleting the last admin
        if ($user['role'] === 'admin') {
            $allAdmins = $this->userModel->getAllAdmins();
            if (count($allAdmins) <= 1) {
                $_SESSION['error'] = 'Cannot delete the last admin user';
                $this->redirect('/users');
                return;
            }
        }

        try {
            $this->userModel->delete($userId);
            $_SESSION['success'] = 'User deleted successfully';
            $this->redirect('/users');

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to delete user: ' . $e->getMessage();
            $this->redirect('/users');
        }
    }

    /**
     * Toggle user active status
     */
    public function toggleStatus($params = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/users');
            return;
        }

        // CSRF Protection
        $this->verifyCsrf('/users');

        $userId = $params['id'] ?? 0;
        $user = $this->userModel->find($userId);

        if (!$user) {
            $_SESSION['error'] = 'User not found';
            $this->redirect('/users');
            return;
        }

        // Prevent deactivating yourself
        if ($userId == Auth::id()) {
            $_SESSION['error'] = 'You cannot deactivate your own account';
            $this->redirect('/users');
            return;
        }

        try {
            $newStatus = $user['is_active'] ? 0 : 1;
            $this->userModel->update($userId, ['is_active' => $newStatus]);

            $_SESSION['success'] = 'User status updated successfully';
            $this->redirect('/users');

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to update user status: ' . $e->getMessage();
            $this->redirect('/users');
        }
    }

    /**
     * Bulk toggle user status (activate or deactivate)
     */
    public function bulkToggleStatus()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/users');
            return;
        }

        $this->verifyCsrf('/users');

        $userIdsJson = $_POST['user_ids'] ?? '[]';
        $userIds = json_decode($userIdsJson, true);
        $action = $_POST['action'] ?? 'activate'; // 'activate' or 'deactivate'

        if (empty($userIds) || !is_array($userIds)) {
            $_SESSION['error'] = 'No users selected';
            $this->redirect('/users');
            return;
        }

        $newStatus = ($action === 'activate') ? 1 : 0;
        $updatedCount = 0;
        $skippedSelf = false;

        foreach ($userIds as $userId) {
            // Prevent modifying your own account
            if ($userId == Auth::id()) {
                $skippedSelf = true;
                continue;
            }

            try {
                $this->userModel->update((int)$userId, ['is_active' => $newStatus]);
                $updatedCount++;
            } catch (\Exception $e) {
                // Continue with next user
            }
        }

        $message = $action === 'activate' ? "Activated $updatedCount user(s)" : "Deactivated $updatedCount user(s)";
        if ($skippedSelf) {
            $message .= ' (skipped your own account)';
        }

        $_SESSION['success'] = $message;
        $this->redirect('/users');
    }

    /**
     * Bulk delete users
     */
    public function bulkDelete()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/users');
            return;
        }

        $this->verifyCsrf('/users');

        $userIdsJson = $_POST['user_ids'] ?? '[]';
        $userIds = json_decode($userIdsJson, true);

        if (empty($userIds) || !is_array($userIds)) {
            $_SESSION['error'] = 'No users selected for deletion';
            $this->redirect('/users');
            return;
        }

        $deletedCount = 0;
        $skippedSelf = false;

        foreach ($userIds as $userId) {
            // Prevent deleting your own account
            if ($userId == Auth::id()) {
                $skippedSelf = true;
                continue;
            }

            // Prevent deleting if this is the last admin
            $user = $this->userModel->find((int)$userId);
            if ($user && $user['role'] === 'admin') {
                $allAdmins = $this->userModel->getAllAdmins();
                if (count($allAdmins) <= 1) {
                    continue; // Skip - can't delete last admin
                }
            }

            try {
                $this->userModel->delete((int)$userId);
                $deletedCount++;
            } catch (\Exception $e) {
                // Continue with next user
            }
        }

        $message = "Successfully deleted $deletedCount user(s)";
        if ($skippedSelf) {
            $message .= ' (skipped your own account)';
        }

        $_SESSION['success'] = $message;
        $this->redirect('/users');
    }
}

