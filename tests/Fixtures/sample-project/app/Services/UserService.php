<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function __construct(
        private UserRepository $userRepository,
        private ?NotificationService $notificationService = null
    ) {}

    /**
     * Create a new user
     */
    public function createUser(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $data['password'] = Hash::make($data['password']);

            $user = $this->userRepository->create($data);

            if ($this->notificationService) {
                $this->notificationService->sendWelcomeEmail($user);
            }

            return $user;
        });
    }

    /**
     * Update user data
     */
    public function updateUser(User $user, array $data): User
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        return $this->userRepository->update($user, $data);
    }

    /**
     * Find user by email
     */
    public function findByEmail(string $email): ?User
    {
        return $this->userRepository->findByEmail($email);
    }

    /**
     * Get active users
     */
    public function getActiveUsers(): array
    {
        return $this->userRepository->getActive();
    }

    /**
     * Deactivate user
     */
    public function deactivateUser(User $user): bool
    {
        return $this->userRepository->update($user, ['active' => false]) !== null;
    }
}
