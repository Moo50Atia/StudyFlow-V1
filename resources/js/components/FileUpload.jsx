import React, { useRef, useState } from 'react';

export default function FileUpload({
  id = 'file-upload',
  name = 'file',
  label = 'Upload file',
  accept = '.pdf',
  multiple = false,
  onChange,
  error = null,
  helperText = 'PDF files up to 50MB each',
  required = false,
  disabled = false,
  className = '',
}) {
  const [dragActive, setDragActive] = useState(false);
  const [selectedFiles, setSelectedFiles] = useState([]);
  const inputRef = useRef(null);

  const handleDrag = (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (e.type === 'dragenter' || e.type === 'dragover') {
      setDragActive(true);
    } else if (e.type === 'dragleave') {
      setDragActive(false);
    }
  };

  const handleDrop = (e) => {
    e.preventDefault();
    e.stopPropagation();
    setDragActive(false);
    if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
      const files = Array.from(e.dataTransfer.files);
      setSelectedFiles(files);
      if (onChange) onChange(files);
    }
  };

  const handleChange = (e) => {
    if (e.target.files && e.target.files.length > 0) {
      const files = Array.from(e.target.files);
      setSelectedFiles(files);
      if (onChange) onChange(files);
    }
  };

  const formatSize = (bytes) => {
    if (!bytes) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return `${parseFloat((bytes / Math.pow(k, i)).toFixed(1))} ${sizes[i]}`;
  };

  return (
    <div className={`space-y-2 ${className}`}>
      {label && (
        <label htmlFor={id} className="block text-sm font-semibold text-slate-800 dark:text-slate-200">
          {label} {required && <span className="text-rose-600 dark:text-rose-400">*</span>}
        </label>
      )}

      <div
        onDragEnter={handleDrag}
        onDragLeave={handleDrag}
        onDragOver={handleDrag}
        onDrop={handleDrop}
        onClick={() => inputRef.current?.click()}
        className={`relative flex flex-col items-center justify-center rounded-2xl border-2 border-dashed p-6 text-center cursor-pointer transition-colors duration-150 ${
          dragActive
            ? 'border-teal-500 bg-teal-50/50 dark:border-teal-400 dark:bg-teal-950/30'
            : error
            ? 'border-rose-300 bg-rose-50/30 dark:border-rose-800 dark:bg-rose-950/20'
            : 'border-slate-300 bg-white hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800/60'
        }`}
      >
        <input
          ref={inputRef}
          id={id}
          name={name}
          type="file"
          accept={accept}
          multiple={multiple}
          onChange={handleChange}
          disabled={disabled}
          className="sr-only"
        />

        <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-400">
          <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
          </svg>
        </div>

        <p className="mt-3 text-sm font-medium text-slate-700 dark:text-slate-300">
          <span className="font-semibold text-teal-700 dark:text-teal-400 underline">Click to select</span> or drag files here
        </p>
        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">{helperText}</p>
      </div>

      {selectedFiles.length > 0 && (
        <ul className="space-y-1.5 pt-1">
          {selectedFiles.map((file, idx) => (
            <li
              key={idx}
              className="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-700 border border-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:border-slate-700"
            >
              <div className="flex items-center gap-2 truncate">
                <svg className="h-4 w-4 text-teal-600 shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                  <path d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" />
                </svg>
                <span className="truncate font-medium">{file.name}</span>
                <span className="text-xs text-slate-400 shrink-0">({formatSize(file.size)})</span>
              </div>
            </li>
          ))}
        </ul>
      )}

      {error && (
        <p role="alert" className="text-sm font-medium text-rose-600 dark:text-rose-400">
          {error}
        </p>
      )}
    </div>
  );
}
