import React, { useState, useRef } from 'react';

export default function SceneViewport({
    src,
    title = '3D Simulation Viewport',
    className = '',
    onFullscreenChange = null,
}) {
    const containerRef = useRef(null);
    const iframeRef = useRef(null);
    const [isFullscreen, setIsFullscreen] = useState(false);
    const [isLoading, setIsLoading] = useState(true);

    const toggleFullscreen = () => {
        if (!containerRef.current) return;

        if (!document.fullscreenElement) {
            containerRef.current.requestFullscreen().then(() => {
                setIsFullscreen(true);
                if (onFullscreenChange) onFullscreenChange(true);
            }).catch(console.error);
        } else {
            document.exitFullscreen().then(() => {
                setIsFullscreen(false);
                if (onFullscreenChange) onFullscreenChange(false);
            }).catch(console.error);
        }
    };

    const reloadScene = () => {
        if (iframeRef.current) {
            setIsLoading(true);
            iframeRef.current.src = iframeRef.current.src;
        }
    };

    return (
        <div
            ref={containerRef}
            className={`relative rounded-3xl overflow-hidden bg-slate-950 border border-slate-800 shadow-xl flex flex-col ${className}`}
        >
            {/* Top Toolbar */}
            <div className="flex items-center justify-between px-4 py-2.5 bg-slate-900/90 border-b border-slate-800 z-10 backdrop-blur-sm">
                <div className="flex items-center gap-2">
                    <span className="w-2.5 h-2.5 rounded-full bg-teal-400 animate-pulse" />
                    <span className="text-xs font-semibold text-slate-200 truncate max-w-xs sm:max-w-md">
                        {title}
                    </span>
                </div>

                <div className="flex items-center gap-1.5">
                    <button
                        type="button"
                        onClick={reloadScene}
                        title="Reload scene"
                        aria-label="Reload scene"
                        className="p-1.5 rounded-lg text-slate-300 hover:text-white hover:bg-slate-800 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-teal-400"
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                    </button>

                    <button
                        type="button"
                        onClick={toggleFullscreen}
                        title={isFullscreen ? 'Exit fullscreen' : 'Enter fullscreen'}
                        aria-label={isFullscreen ? 'Exit fullscreen' : 'Enter fullscreen'}
                        className="p-1.5 rounded-lg text-slate-300 hover:text-white hover:bg-slate-800 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-teal-400"
                    >
                        {isFullscreen ? (
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M9 9L4 4m0 0l5 0m-5 0l0 5m11-5l5 0m0 0l0 5m0-5l-5 5m5 11l-5-5m5 5l0-5m0 5l-5 0m-11 0l5-5m-5 5l0-5m0 5l5 0" />
                            </svg>
                        ) : (
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-5h-4m4 0v4m0-4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                            </svg>
                        )}
                    </button>
                </div>
            </div>

            {/* Viewport Frame */}
            <div className="relative w-full aspect-[16/10] sm:aspect-video bg-black flex-1 min-h-[360px]">
                {isLoading && (
                    <div className="absolute inset-0 flex flex-col items-center justify-center bg-slate-950 text-slate-400 z-0">
                        <div className="w-10 h-10 border-4 border-teal-500 border-t-transparent rounded-full animate-spin mb-3" />
                        <span className="text-xs font-medium tracking-wide">Loading interactive simulation...</span>
                    </div>
                )}

                {src ? (
                    <iframe
                        ref={iframeRef}
                        src={src}
                        title={title}
                        onLoad={() => setIsLoading(false)}
                        className="w-full h-full border-0 relative z-0"
                        sandbox="allow-scripts allow-same-origin allow-popups"
                    />
                ) : (
                    <div className="w-full h-full flex items-center justify-center text-slate-500 text-sm">
                        No scene preview available
                    </div>
                )}
            </div>

            {/* Controls Helper Footer */}
            <div className="px-4 py-2 bg-slate-900/60 border-t border-slate-800 text-[13px] text-slate-400 flex items-center justify-between flex-wrap gap-2">
                <span>Left click + drag to orbit • Right click to pan • Scroll to zoom</span>
                <span className="font-mono text-xs text-slate-500">WebGL / Three.js</span>
            </div>
        </div>
    );
}
