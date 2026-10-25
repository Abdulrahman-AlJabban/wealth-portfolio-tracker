import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const LINK_CLASSES =
    'rounded font-medium text-gray-700 underline underline-offset-2 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500';

export default function Login({
    status,
    canResetPassword,
}: {
    status?: string;
    canResetPassword: boolean;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false as boolean,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout
            title="Log in"
            description="Track shared portfolios of stocks, crypto, real estate and cash."
            footer={
                <>
                    No account yet?{' '}
                    <Link href={route('register')} className={LINK_CLASSES}>
                        Create one
                    </Link>
                </>
            }
        >
            <Head title="Log in" />

            {status && (
                <div
                    role="status"
                    className="mb-6 rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-800"
                >
                    {status}
                </div>
            )}

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel htmlFor="email" value="Email" />

                    <TextInput
                        id="email"
                        aria-invalid={errors.email ? true : undefined}
                        aria-describedby={errors.email ? 'email-error' : undefined}
                        type="email"
                        name="email"
                        value={data.email}
                        className="mt-1 block w-full"
                        autoComplete="username"
                        isFocused={true}
                        required
                        onChange={(e) => setData('email', e.target.value)}
                    />

                    <InputError id="email-error" message={errors.email} className="mt-1" />
                </div>

                <div>
                    <div className="flex items-baseline justify-between gap-4">
                        <InputLabel htmlFor="password" value="Password" />

                        {canResetPassword && (
                            <Link
                                href={route('password.request')}
                                className={`text-sm ${LINK_CLASSES}`}
                            >
                                Forgot your password?
                            </Link>
                        )}
                    </div>

                    <TextInput
                        id="password"
                        aria-invalid={errors.password ? true : undefined}
                        aria-describedby={errors.password ? 'password-error' : undefined}
                        type="password"
                        name="password"
                        value={data.password}
                        className="mt-1 block w-full"
                        autoComplete="current-password"
                        required
                        onChange={(e) => setData('password', e.target.value)}
                    />

                    <InputError id="password-error" message={errors.password} className="mt-1" />
                </div>

                <label className="flex items-center">
                    <Checkbox
                        name="remember"
                        checked={data.remember}
                        onChange={(e) =>
                            setData('remember', (e.target.checked || false) as false)
                        }
                    />
                    <span className="ms-2 text-sm text-gray-600">Remember me</span>
                </label>

                <PrimaryButton type="submit" className="w-full justify-center" disabled={processing}>
                    {processing ? 'Logging in…' : 'Log in'}
                </PrimaryButton>
            </form>
        </GuestLayout>
    );
}
