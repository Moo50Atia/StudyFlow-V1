import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import StatusBadge from '../../components/StatusBadge';

export default function Devices({ devices = [], is_platform_owner = false }) {
    const [deviceList, setDeviceList] = useState(devices);
    const [invitation, setInvitation] = useState(null);
    const [deviceName, setDeviceName] = useState('Local Processing Worker');
    const [isCreating, setIsCreating] = useState(false);
    const [actionError, setActionError] = useState('');

    const csrf = typeof document !== 'undefined' ? document.querySelector('meta[name="csrf-token"]')?.content || '' : '';

    const createInvitation = async (e) => {
        e.preventDefault();
        setActionError('');
        setIsCreating(true);
        try {
            const resp = await fetch('/v3/admin/devices/invitations', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    device_name: deviceName,
                    scopes: ['tenant:all'],
                    capabilities: ['notebooklm_authoring_v1', 'scene_return_v1'],
                }),
            });
            const data = await resp.json();
            if (resp.ok) {
                setInvitation(data);
            } else {
                setActionError(data.message || 'Failed to create pairing invitation');
            }
        } catch (err) {
            setActionError('Network error while creating pairing invitation');
        } finally {
            setIsCreating(false);
        }
    };

    const revokeDevice = async (deviceId) => {
        if (!confirm('Are you sure you want to revoke this processing device? It will be disconnected immediately.')) {
            return;
        }
        setActionError('');
        try {
            const resp = await fetch(`/v3/admin/devices/${deviceId}/revoke`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    Accept: 'application/json',
                },
                body: JSON.stringify({ reason: 'admin_manual_revocation' }),
            });
            if (resp.ok) {
                setDeviceList((prev) =>
                    prev.map((d) => (d.id === deviceId ? { ...d, status: 'revoked' } : d))
                );
            } else {
                const data = await resp.json();
                setActionError(data.message || 'Failed to revoke device');
            }
        } catch (err) {
            setActionError('Network error while revoking device');
        }
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Operations Console', url: '/v3/admin/workspace' },
        { label: 'Processing Devices' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Processing Devices — EduPlatform Operations" />

            <PageHeader
                title="Processing Devices"
                eyebrow="Device Topology & Access Grants"
                description="Manage enrolled outbound processing workers. Devices claim scoped tasks via outbound HTTPS without storing server credentials or provider cookies on the server."
            />

            {actionError && (
                <div role="alert" className="mb-6 p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/40 border border-rose-300 dark:border-rose-800 text-rose-900 dark:text-rose-200 text-sm font-medium">
                    {actionError}
                </div>
            )}

            {/* Platform Owner Pairing Invitation Card */}
            {is_platform_owner && (
                <div className="mb-8 p-6 bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                    <h3 className="text-base font-bold text-slate-900 dark:text-slate-100 mb-1">
                        Enroll New Processing Device
                    </h3>
                    <p className="text-xs text-slate-500 dark:text-slate-400 mb-4">
                        Generate a single-use masked pairing code for an outbound worker. Secrets expire in 15 minutes.
                    </p>

                    <form onSubmit={createInvitation} className="flex flex-wrap items-center gap-3">
                        <input
                            type="text"
                            value={deviceName}
                            onChange={(e) => setDeviceName(e.target.value)}
                            required
                            placeholder="Device Name (e.g., Physics Lab Worker #1)"
                            className="text-sm font-semibold rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-[#121e1a] text-slate-900 dark:text-slate-100 px-3 py-2 w-72 focus:ring-2 focus:ring-teal-500"
                        />
                        <Button type="submit" variant="primary" size="sm" disabled={isCreating} isLoading={isCreating}>
                            Generate Pairing Code
                        </Button>
                    </form>

                    {invitation && (
                        <div className="mt-4 p-4 rounded-2xl bg-teal-50/70 dark:bg-teal-950/40 border border-teal-200 dark:border-teal-800">
                            <span className="text-xs font-bold uppercase tracking-wider text-teal-800 dark:text-teal-300 block mb-1">
                                One-Time Pairing Code (Display Only)
                            </span>
                            <div className="font-mono text-lg font-bold text-teal-950 dark:text-teal-100 tracking-wider">
                                {invitation.pairing_secret || invitation.pairing_code}
                            </div>
                            <p className="text-xs text-teal-900/80 dark:text-teal-300/80 mt-1">
                                Run on worker: <code className="bg-teal-100 dark:bg-teal-900 px-1.5 py-0.5 rounded text-[11px]">local-worker enroll --server {window.location.origin}</code>
                            </p>
                        </div>
                    )}
                </div>
            )}

            {/* Enrolled Devices Table */}
            <div className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm overflow-hidden">
                <div className="p-6 border-b border-slate-100 dark:border-[#263932]">
                    <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                        Enrolled Devices ({deviceList.length})
                    </h3>
                </div>

                {deviceList.length === 0 ? (
                    <div className="p-12 text-center text-sm text-slate-500 dark:text-slate-400">
                        No processing devices have been enrolled yet.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-slate-50 dark:bg-[#182823] text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">
                                <tr>
                                    <th className="px-6 py-3">Device</th>
                                    <th className="px-6 py-3">Status</th>
                                    <th className="px-6 py-3">Capabilities</th>
                                    <th className="px-6 py-3">Last Seen</th>
                                    <th className="px-6 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-[#263932]">
                                {deviceList.map((d) => (
                                    <tr key={d.id} className="hover:bg-slate-50/50 dark:hover:bg-[#182823]/50 transition-colors">
                                        <td className="px-6 py-4">
                                            <div className="font-semibold text-slate-900 dark:text-slate-100">
                                                {d.name}
                                            </div>
                                            <div className="font-mono text-xs text-slate-400">
                                                {d.id}
                                            </div>
                                        </td>
                                        <td className="px-6 py-4">
                                            <StatusBadge status={d.status} />
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="flex flex-wrap gap-1">
                                                {(d.capabilities || []).map((cap, i) => (
                                                    <span key={i} className="px-2 py-0.5 text-[11px] font-mono rounded bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                                        {cap}
                                                    </span>
                                                ))}
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 text-xs text-slate-500 dark:text-slate-400">
                                            {d.last_seen_at ? new Date(d.last_seen_at).toLocaleString() : 'Never'}
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            {is_platform_owner && d.status === 'active' && (
                                                <Button
                                                    type="button"
                                                    variant="secondary"
                                                    size="sm"
                                                    onClick={() => revokeDevice(d.id)}
                                                    className="text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950"
                                                >
                                                    Revoke
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
