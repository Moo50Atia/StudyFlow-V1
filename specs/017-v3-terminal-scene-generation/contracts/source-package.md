# Static HTML/CSS/JavaScript Scene Contract

This current owner-directed contract supersedes the earlier React/TypeScript/Vite contract in this feature directory. It applies to the local terminal flow only; Spec 011 and application package contracts remain unchanged.

## Provider response

Return exactly one JSON object:

```json
{
  "schema_version": 1,
  "files": [
    {"path": "index.html", "content": "..."},
    {"path": "styles.css", "content": "..."},
    {"path": "script.js", "content": "..."}
  ]
}
```

The object has exactly `schema_version` and `files`. `files` has exactly three unique entries and exactly these paths. Every content value is a nonempty UTF-8 string. `index.html` references `./styles.css` and `./script.js`. Each file is limited to 1 MiB and the combined source to 4 MiB. No dependency declarations, package files, TypeScript, JSX, generated build configuration, or other paths are accepted. The model output parser does not evaluate HTML/JS, judge educational content, or validate scene types.

## Materialization and recovery

Save the provider receipt before parsing. Save all three source files with byte counts and SHA-256 values under the selected scene's immutable attempt directory. Local build/materialization copies the three files to that build's `dist/`, verifies the nonempty `index.html`, records file hashes, writes the manifest, and atomically publishes `current.json`. It does not run npm, Node, TypeScript, Vite, or generated JavaScript. A build-only retry uses retained files and makes zero provider requests. A provider retry creates a new attempt and records possible duplicate provider work when the previous request was uncertain.

The manifest identifies `kind: "local_static_html_scene"`, `entrypoint: "index.html"`, source-manifest digest, provider/model/response metadata, prompt and Lesson identity, and exact output file hashes. `verification.static_files_materialized` is true on a successful materialization. Type checking, browser execution, educational qualification, and teacher review remain unperformed and are recorded as such.

## Selector

`generate_scenes.py run --scene-key KEY` starts a run scoped to the exact captured scene identity. Repeat the flag for multiple scenes. Unknown or duplicate selectors fail before provider dispatch. The scene-key selection is part of output-run identity; resuming the same output with a different selection is rejected. `--retry-scene KEY` remains the explicit provider-retry operation for a previously failed/uncertain eligible scene in an existing run.
