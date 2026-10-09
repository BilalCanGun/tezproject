<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Users/Index', [
            'users' => User::query()
                ->withCount(['conductedSessions', 'ratings'])
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role_label' => $user->role->label(),
                    'is_active' => $user->is_active,
                    'last_login_at' => $user->last_login_at?->toIso8601String(),
                    'sessions_count' => $user->conducted_sessions_count,
                    'ratings_count' => $user->ratings_count,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Users/Form', ['user' => null, 'roles' => $this->roles()]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users')],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
            'is_active' => ['boolean'],
        ]);

        $user = User::create($validated);
        $audit->record('user.created', $user, ['role' => $user->role->value]);

        return redirect()->route('admin.users.index')->with('success', 'Kullanıcı oluşturuldu.');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('Admin/Users/Form', [
            'user' => [...$user->only(['id', 'name', 'email', 'is_active']), 'role' => $user->role->value],
            'roles' => $this->roles(),
        ]);
    }

    public function update(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users')->ignore($user)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['nullable', 'confirmed', Password::min(10)->letters()->numbers()],
            'is_active' => ['boolean'],
        ]);

        if ($user->is($request->user()) && (! $validated['is_active'] || $validated['role'] !== UserRole::Admin->value)) {
            return back()->with('error', 'Kendi yönetici yetkinizi kaldıramaz veya hesabınızı devre dışı bırakamazsınız.');
        }

        $user->update(array_filter($validated, fn ($value, $key) => $key !== 'password' || filled($value), ARRAY_FILTER_USE_BOTH));
        $audit->record('user.updated', $user, ['role' => $user->role->value, 'is_active' => $user->is_active]);

        return redirect()->route('admin.users.index')->with('success', 'Kullanıcı güncellendi.');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function roles(): array
    {
        return collect(UserRole::cases())->map(fn ($role) => ['value' => $role->value, 'label' => $role->label()])->all();
    }
}
