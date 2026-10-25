import React, { useEffect, useRef } from 'react';
import Button from './Button';

export default function ConfirmDialog({
    isOpen,
    onClose,
    onConfirm,
    title = 'Confirm action',
    description = 'Are you sure you want to proceed with this action?',
    confirmText = 'Confirm',
    cancelText = 'Cancel',
    variant = 'danger',
    isLoading = false,
}) {
    const dialogRef = useRef(null);
    const confirmBtnRef = useRef(null);

    useEffect(() => {
        if (!isOpen) return;

        // Focus first actionable element
        const timer = setTimeout(() => {
            confirmBtnRef.current?.focus();
        }, 50);

        const handleKeyDown = (e) => {
            if (e.key === 'Escape' && !isLoading) {
                onClose();
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => {
            clearTimeout(timer);
            window.removeEventListener('keydown', handleKeyDown);
        };
    }, [isOpen, isLoading, onClose]);

    if (!isOpen) return null;

    return (
        <div
            className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 dark:bg-black/75 backdrop-blur-sm flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="confirm-dialog-title"
            aria-describedby="confirm-dialog-description"
        >
            <div
                ref={dialogRef}
                className="relative w-full max-w-md bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] shadow-2xl p-6 sm:p-8"
            >
                <div className="flex items-start gap-4">
                    {variant === 'danger' ? (
                        <div className="flex-shrink-0 w-11 h-11 rounded-2xl bg-rose-100 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center">
                            <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                    ) : (
                        <div className="flex-shrink-0 w-11 h-11 rounded-2xl bg-amber-100 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                            <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                    )}

                    <div className="flex-1">
                        <h2 id="confirm-dialog-title" className="text-lg font-bold text-slate-900 dark:text-slate-100">
                            {title}
                        </h2>
                        <p id="confirm-dialog-description" className="mt-2 text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                            {description}
                        </p>
                    </div>
                </div>

                <div className="mt-6 flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={onClose}
                        disabled={isLoading}
                    >
                        {cancelText}
                    </Button>
                    <Button
                        ref={confirmBtnRef}
                        type="button"
                        variant={variant === 'danger' ? 'danger' : 'primary'}
                        onClick={onConfirm}
                        isLoading={isLoading}
                    >
                        {confirmText}
                    </Button>
                </div>
            </div>
        </div>
    );
}
