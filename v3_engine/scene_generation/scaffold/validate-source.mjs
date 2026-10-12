#!/usr/bin/env node
/**
 * Trusted source import validator for terminal scene generation (Spec 017).
 * Inspects all TS/TSX files in the project to enforce strict import boundaries.
 */

import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";

const NODE_BUILTINS = new Set([
  "assert", "async_hooks", "buffer", "child_process", "cluster", "console",
  "constants", "crypto", "dgram", "diagnostics_channel", "dns", "domain",
  "events", "fs", "fs/promises", "http", "http2", "https", "inspector",
  "module", "net", "os", "path", "path/posix", "path/win32", "perf_hooks",
  "process", "punycode", "querystring", "readline", "repl", "stream",
  "stream/promises", "stream/consumers", "stream/web", "string_decoder",
  "sys", "timers", "timers/promises", "tls", "trace_events", "tty",
  "url", "util", "util/types", "v8", "vm", "wasi", "worker_threads", "zlib"
]);

const args = process.argv.slice(2);
if (args.length < 2) {
  console.error("Usage: node validate-source.mjs <project-dir> <allowed-packages-json>");
  process.exit(1);
}

const projectDir = path.resolve(args[0]);
let allowedPackages;
try {
  allowedPackages = new Set(JSON.parse(args[1]));
} catch (err) {
  console.error("Failed to parse allowed-packages-json:", err.message);
  process.exit(1);
}

// Always allow core runtime and internal assets
allowedPackages.add("react");
allowedPackages.add("react-dom");
allowedPackages.add("react/jsx-runtime");

async function loadTypeScript() {
  const tsPath = path.join(projectDir, "node_modules", "typescript", "lib", "typescript.js");
  if (fs.existsSync(tsPath)) {
    const mod = await import(tsPath);
    return mod.default || mod;
  }
  // Fallback to global/current node resolution
  const mod = await import("typescript");
  return mod.default || mod;
}

function getPackageBaseName(specifier) {
  if (specifier.startsWith("@")) {
    const parts = specifier.split("/");
    return parts.length >= 2 ? `${parts[0]}/${parts[1]}` : specifier;
  }
  return specifier.split("/")[0];
}

function collectSourceFiles(dir, files = []) {
  if (!fs.existsSync(dir)) return files;
  const entries = fs.readdirSync(dir, { withFileTypes: true });
  for (const entry of entries) {
    const fullPath = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      collectSourceFiles(fullPath, files);
    } else if (entry.isFile() && (entry.name.endsWith(".ts") || entry.name.endsWith(".tsx"))) {
      files.push(fullPath);
    }
  }
  return files;
}

async function validate() {
  let ts;
  try {
    ts = await loadTypeScript();
  } catch (err) {
    console.error("Failed to load TypeScript compiler for AST validation:", err.message);
    process.exit(1);
  }

  const srcDir = path.join(projectDir, "src");
  const sourceFiles = collectSourceFiles(srcDir);
  const errors = [];

  for (const filePath of sourceFiles) {
    const content = fs.readFileSync(filePath, "utf-8");
    const sourceFile = ts.createSourceFile(
      filePath,
      content,
      ts.ScriptTarget.ESNext,
      true
    );

    function checkSpecifier(specifierNode, context) {
      if (!specifierNode) return;
      if (!ts.isStringLiteral(specifierNode)) {
        errors.push(`${filePath}: Non-literal ${context} is forbidden.`);
        return;
      }

      const specifier = specifierNode.text;

      // Reject URLs
      if (specifier.startsWith("http:") || specifier.startsWith("https:") || specifier.startsWith("//")) {
        errors.push(`${filePath}: External URL import '${specifier}' is forbidden.`);
        return;
      }

      // Reject Windows path separators
      if (specifier.includes("\\")) {
        errors.push(`${filePath}: Backslash in import '${specifier}' is forbidden.`);
        return;
      }

      // Reject absolute filesystem paths
      if (path.isAbsolute(specifier)) {
        errors.push(`${filePath}: Absolute filesystem import '${specifier}' is forbidden.`);
        return;
      }

      // Check relative imports
      if (specifier.startsWith(".")) {
        const resolved = path.resolve(path.dirname(filePath), specifier);
        const relative = path.relative(projectDir, resolved);
        if (relative === ".." || relative.startsWith(`..${path.sep}`) || path.isAbsolute(relative)) {
          errors.push(`${filePath}: Relative import '${specifier}' escapes project directory.`);
        }
        return;
      }

      // Check bare specifiers (packages)
      const cleanSpec = specifier.startsWith("node:") ? specifier.slice(5) : specifier;
      if (NODE_BUILTINS.has(cleanSpec) || specifier.startsWith("node:")) {
        errors.push(`${filePath}: Node.js built-in module '${specifier}' is forbidden.`);
        return;
      }

      const basePkg = getPackageBaseName(specifier);
      if (!allowedPackages.has(basePkg) && !allowedPackages.has(specifier)) {
        errors.push(`${filePath}: Package '${specifier}' is not in declared dependencies.`);
      }
    }

    function visit(node) {
      if (ts.isImportDeclaration(node)) {
        checkSpecifier(node.moduleSpecifier, "import");
      } else if (ts.isExportDeclaration(node) && node.moduleSpecifier) {
        checkSpecifier(node.moduleSpecifier, "export from");
      } else if (ts.isCallExpression(node)) {
        if (node.expression.kind === ts.SyntaxKind.ImportKeyword) {
          checkSpecifier(node.arguments[0], "dynamic import()");
        } else if (ts.isIdentifier(node.expression) && node.expression.text === "require") {
          checkSpecifier(node.arguments[0], "require()");
        }
      }
      ts.forEachChild(node, visit);
    }

    visit(sourceFile);
  }

  if (errors.length > 0) {
    console.error("Source validation failed:\n" + errors.join("\n"));
    process.exit(1);
  }

  process.exit(0);
}

validate().catch((err) => {
  console.error("Unexpected error in validate-source.mjs:", err);
  process.exit(1);
});
