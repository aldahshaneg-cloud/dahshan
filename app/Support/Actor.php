<?php

declare(strict_types=1);

namespace App\Support;

/**
 * الفاعل اللي بينفّذ الطلب — المقابل للمصفوفة اللي require_auth() بترجّعها.
 *
 * النظام الحالي بيرجّع مصفوفة بالمفاتيح دي بالظبط، والكود جواه بيقراها
 * كـ $user['role'] و $user['branch_id'] وهكذا. الكائن ده بيحافظ على نفس
 * المعاني بالحرف — بما فيها إن **عميل التطبيق مالوش user_id** (null) وإن
 * **الموظف مالوش customer_id** (null). التفرقة دي مستعملة في منطق النطاق
 * في أماكن كتير.
 */
final class Actor
{
    public function __construct(
        public readonly ?int $userId,
        public readonly ?int $customerId,
        public readonly string $username,
        public readonly string $role,
        public readonly ?int $branchId,
        public readonly string $name,
    ) {
    }

    public static function customer(int $customerId, string $username, string $name = ''): self
    {
        return new self(
            userId: null,
            customerId: $customerId,
            username: $username !== '' ? $username : ('customer:' . $customerId),
            role: 'customer',
            branchId: null,
            name: $name,
        );
    }

    public static function staff(
        int $userId,
        string $username,
        string $role,
        ?int $branchId,
        string $name = '',
    ): self {
        return new self(
            userId: $userId,
            customerId: null,
            username: $username,
            role: $role,
            branchId: $branchId,
            name: $name,
        );
    }

    public function isCustomer(): bool
    {
        return $this->role === 'customer';
    }

    /** موظف الشركة — نفس تعريف entities_require_staff() */
    public function isStaff(): bool
    {
        return in_array($this->role, ['admin', 'branch', 'callcenter'], true);
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * نفس شكل مصفوفة require_auth() **بالحرف** — بما فيها اختلاف مجموعة
     * المفاتيح بين الحالتين، وده مهم لأن /api/me بترجّع المصفوفة دي كما هي:
     *
     *   عميل التطبيق : user_id(null) · customer_id · username · role · branch_id(null) · name
     *   موظف         : user_id · username · role · branch_id · name      ← **مفيش customer_id**
     *
     * ترتيب المفاتيح متطابق مع الأصل كمان عشان الردود تبقى متطابقة نصًا.
     */
    public function toLegacyArray(): array
    {
        if ($this->isCustomer()) {
            return [
                'user_id'     => null,
                'customer_id' => $this->customerId,
                'username'    => $this->username,
                'role'        => $this->role,
                'branch_id'   => null,
                'name'        => $this->name,
            ];
        }

        return [
            'user_id'   => $this->userId,
            'username'  => $this->username,
            'role'      => $this->role,
            'branch_id' => $this->branchId,
            'name'      => $this->name,
        ];
    }
}
