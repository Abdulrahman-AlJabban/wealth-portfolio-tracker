import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import RoleBadge from '@/Components/RoleBadge';
import TextInput from '@/Components/TextInput';
import { MemberRow, Role } from '@/types/portfolio';
import { router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

const ROLES: { value: Role; label: string }[] = [
    { value: 'viewer', label: 'Viewer' },
    { value: 'editor', label: 'Editor' },
    { value: 'owner', label: 'Owner' },
];

const selectClass =
    'rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:opacity-50';

function AddMemberForm({ portfolioId }: { portfolioId: number }) {
    const form = useForm({ email: '', role: 'viewer' as Role });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('members.store', portfolioId), {
            preserveScroll: true,
            onSuccess: () => form.reset('email'),
        });
    }

    return (
        <form onSubmit={submit} aria-label="Add a member" className="flex flex-col gap-3 sm:flex-row sm:items-start">
            <div className="sm:flex-1">
                <InputLabel htmlFor="member-email" value="Email" />
                <TextInput
                    id="member-email"
                    type="email"
                    autoComplete="off"
                    value={form.data.email}
                    onChange={(event) => form.setData('email', event.target.value)}
                    required
                    aria-invalid={form.errors.email ? true : undefined}
                    aria-describedby={form.errors.email ? 'member-email-error' : undefined}
                    className="mt-1 block w-full"
                />
                <InputError id="member-email-error" message={form.errors.email} className="mt-1" />
            </div>
            <div>
                <InputLabel htmlFor="member-role" value="Role" />
                <select
                    id="member-role"
                    value={form.data.role}
                    onChange={(event) => form.setData('role', event.target.value as Role)}
                    aria-invalid={form.errors.role ? true : undefined}
                    aria-describedby={form.errors.role ? 'member-role-error' : undefined}
                    className={`mt-1 block w-full ${selectClass}`}
                >
                    {ROLES.map((role) => (
                        <option key={role.value} value={role.value}>
                            {role.label}
                        </option>
                    ))}
                </select>
                <InputError id="member-role-error" message={form.errors.role} className="mt-1" />
            </div>
            <div className="sm:pt-6">
                <PrimaryButton type="submit" disabled={form.processing}>
                    {form.processing ? 'Adding…' : 'Add member'}
                </PrimaryButton>
            </div>
        </form>
    );
}

export default function MembersPanel({
    portfolioId,
    members,
    canManage,
}: {
    portfolioId: number;
    members: MemberRow[];
    canManage: boolean;
}) {
    const [busyId, setBusyId] = useState<number | null>(null);
    const [confirmingId, setConfirmingId] = useState<number | null>(null);
    // A refusal ("A portfolio needs at least one owner.") belongs to the row that caused it.
    const [rowError, setRowError] = useState<{ id: number; message: string } | null>(null);

    const requestOptions = (id: number) => ({
        preserveScroll: true,
        onStart: () => {
            setBusyId(id);
            setRowError(null);
        },
        onError: (errors: Record<string, string>) =>
            setRowError({ id, message: errors.role ?? errors.member ?? 'That change was refused.' }),
        onFinish: () => {
            setBusyId(null);
            setConfirmingId(null);
        },
    });

    function changeRole(member: MemberRow, role: Role) {
        router.patch(route('members.update', [portfolioId, member.id]), { role }, requestOptions(member.id));
    }

    function remove(member: MemberRow) {
        router.delete(route('members.destroy', [portfolioId, member.id]), requestOptions(member.id));
    }

    return (
        <section aria-labelledby="members-heading" className="space-y-4">
            <h3 id="members-heading" className="text-base font-medium text-gray-900">
                Members
            </h3>

            <ul className="divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white">
                {members.map((member) => (
                    <li key={member.id} className="px-4 py-3 text-sm">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="min-w-0">
                                <span className="font-medium text-gray-900">{member.user.name}</span>
                                <span className="block truncate text-xs text-gray-500">{member.user.email}</span>
                            </div>

                            {!canManage && <RoleBadge role={member.role} />}

                            {canManage && (
                                <div className="flex flex-wrap items-center gap-3">
                                    <label htmlFor={`role-${member.id}`} className="sr-only">
                                        Role for {member.user.name}
                                    </label>
                                    {/* Controlled by the server's value: a refused change snaps back. */}
                                    <select
                                        id={`role-${member.id}`}
                                        value={member.role}
                                        disabled={busyId !== null}
                                        onChange={(event) => changeRole(member, event.target.value as Role)}
                                        aria-describedby={rowError?.id === member.id ? `member-${member.id}-error` : undefined}
                                        className={selectClass}
                                    >
                                        {ROLES.map((role) => (
                                            <option key={role.value} value={role.value}>
                                                {role.label}
                                            </option>
                                        ))}
                                    </select>

                                    {confirmingId === member.id ? (
                                        <span className="inline-flex items-center gap-2">
                                            <span className="text-xs text-gray-600">Remove {member.user.name}?</span>
                                            <button
                                                type="button"
                                                onClick={() => remove(member)}
                                                disabled={busyId !== null}
                                                className="rounded bg-gray-800 px-2 py-1 text-xs font-medium text-white hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50"
                                            >
                                                {busyId === member.id ? 'Removing…' : 'Confirm'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setConfirmingId(null)}
                                                className="rounded px-2 py-1 text-xs text-gray-600 hover:text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                            >
                                                Cancel
                                            </button>
                                        </span>
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={() => setConfirmingId(member.id)}
                                            className="rounded text-sm font-medium text-gray-700 underline-offset-2 hover:underline focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                        >
                                            Remove
                                        </button>
                                    )}
                                </div>
                            )}
                        </div>

                        {rowError?.id === member.id && (
                            <p id={`member-${member.id}-error`} role="alert" className="mt-2 text-xs text-red-600">
                                {rowError.message}
                            </p>
                        )}
                    </li>
                ))}
            </ul>

            {canManage && (
                <div className="rounded-lg border border-gray-200 bg-white p-4">
                    <AddMemberForm portfolioId={portfolioId} />
                </div>
            )}
        </section>
    );
}
