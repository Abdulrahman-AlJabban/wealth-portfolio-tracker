import { Role } from '@/types/portfolio';

const LABELS: Record<Role, string> = {
    owner: 'Owner',
    editor: 'Editor',
    viewer: 'Viewer',
};

// The role is always spelled out, so the badge never relies on color alone.
export default function RoleBadge({ role }: { role: Role }) {
    const tone =
        role === 'owner'
            ? 'border-indigo-200 bg-indigo-50 text-indigo-700'
            : 'border-gray-200 bg-gray-50 text-gray-700';

    return (
        <span
            className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${tone}`}
        >
            {LABELS[role]}
        </span>
    );
}
