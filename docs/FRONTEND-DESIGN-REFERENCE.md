# EduPlatform Frontend Design Reference

This design reference defines the design system, semantic tokens, typography, component rules, and page layout architectures for EduPlatform's React/Inertia interface redesign.

---

## 1. Aesthetic Direction: The Calm Educational Studio

EduPlatform is a high-velocity, curriculum-to-interactive visual scenes engine.
The design direction is **The Calm Educational Studio**:
- **Not a cluttered LMS:** No widget-heavy dashboards or badge spam.
- **Not a generic SaaS template:** Grounded in real academic documents, textbook structures, structured lessons, and interactive 3D simulations.
- **Workflow-driven:** Clear visual rails illustrating the progression from **PDF Intake $\to$ Extraction $\to$ Lesson Mapping $\to$ 3D Scene Generation $\to$ Teacher Review & Qualification $\to$ Student Publication**.
- **Content-first readability:** Generous margins, clean typography hierarchy, high-contrast bilingual (English & Egyptian Arabic) readability, and focused interactive viewports.

---

## 2. Named Semantic Color Tokens (Light & Dark)

The color system uses CSS custom properties paired with Tailwind utility classes. Every surface, text, and border element references these tokens to provide instant, seamless dark/light switching.

| Token | Semantic Purpose | Light Mode Value | Dark Mode Value |
| :--- | :--- | :--- | :--- |
| `--canvas-bg` | Full-page background | `#f6f8f6` (Pale studio linen) | `#0a1210` (Deep spruce charcoal) |
| `--surface-base` | Main card & container background | `#ffffff` (Pure white) | `#121e1a` (Dark slate spruce) |
| `--surface-elevated` | Modal, dropdown, and active card | `#fbfdfb` (Slight tint) | `#182823` (Elevated spruce) |
| `--surface-subtle` | Table header, muted strip, pill bg | `#eef2ee` (Soft stone) | `#1d302a` (Muted panel) |
| `--text-primary` | Main headlines & critical content | `#0f172a` (Slate 900) | `#f1f5f9` (Slate 100) |
| `--text-secondary` | Educational body text & descriptions| `#334155` (Slate 700) | `#cbd5e1` (Slate 300) |
| `--text-muted` | Captions, timestamps, metadata | `#64748b` (Slate 500) | `#94a3b8` (Slate 400) |
| `--border-subtle` | Card borders, dividers | `#e2e8f0` (Slate 200) | `#263932` (Deep sage border) |
| `--border-strong` | Active input borders, table borders | `#cbd5e1` (Slate 300) | `#395249` (Visible dark border) |
| `--brand-primary` | Primary action buttons & highlights | `#0f766e` (Teal 700) | `#2dd4bf` (Teal 400) |
| `--brand-hover` | Hover states on primary buttons | `#115e59` (Teal 800) | `#14b8a6` (Teal 500) |
| `--brand-subtle` | Active nav, badge bg, selection | `#ccfbf1` (Teal 100) | `#134e4a` (Teal 900) |
| `--status-success` | Approved, completed, published | `#059669` (Emerald 600) | `#34d399` (Emerald 400) |
| `--status-success-bg`| Success pills & notice boxes | `#ecfdf5` (Emerald 50) | `#064e3b` (Emerald 900) |
| `--status-warning` | Pending review, qualification alert| `#d97706` (Amber 600) | `#fbbf24` (Amber 400) |
| `--status-warning-bg`| Warning notice background | `#fffbeb` (Amber 50) | `#451a03` (Amber 950) |
| `--status-error` | Validation error, rejection, failed | `#e11d48` (Rose 600) | `#f43f5e` (Rose 400) |
| `--status-error-bg` | Error summary & alert boxes | `#fff1f2` (Rose 50) | `#4c0519` (Rose 950) |
| `--status-info` | AI generation, processing step | `#4f46e5` (Indigo 600) | `#818cf8` (Indigo 400) |
| `--status-info-bg` | Processing progress boxes | `#eef2ff` (Indigo 50) | `#1e1b4b` (Indigo 950) |
| `--focus-ring` | Keyboard outline rings | `#0f766e` (Teal 700) | `#5eead4` (Teal 300) |

---

## 3. Typography Hierarchy & Bilingual Support

### Fonts
- **Latin & Numbers:** `Inter`, `Figtree`, system-ui, sans-serif.
- **Arabic Content:** `Noto Sans Arabic`, `Cairo`, system-ui, sans-serif.
- **Code & LaTeX/Formulas:** `ui-monospace`, `SFMono-Regular`, `Menlo`, monospace.

### Scale Rules (Enforcing `AccessibilityFirst` & `TypographySystem`)
* **Display (Hero Titles):** `36px–48px` (2.25rem–3rem), bold, `tracking-tight`, line-height `1.2`.
* **Page Heading (H1):** `28px–32px` (1.75rem–2rem), font-bold, line-height `1.25`.
* **Section Heading (H2):** `20px–24px` (1.25rem–1.5rem), font-semibold, line-height `1.35`.
* **Subsection Heading (H3):** `18px` (1.125rem), font-medium, line-height `1.4`.
* **Educational Body (Concept Explanations, Prose):** `16px–18px` (1rem–1.125rem), line-height `1.65`, max-width `75ch` for optimal reading comprehension.
* **Captions, Form Labels, Table Headers:** Minimum **14px** (`0.875rem`), font-medium. **No text in the system may be smaller than 14px**.

### Bilingual & RTL Handling
- Mixed content: English course codes/formulas paired with Egyptian Arabic (`egyptian_explain`) explanations.
- Explicit `dir="rtl"` applied to Arabic translation callouts.
- Formulas and code blocks remain strictly `dir="ltr"` with clear monospace background containers.

---

## 4. Spacing, Borders, Shadows, and Focus Rules

### Spacing Scale
Strictly adhere to the `UIConsistency` scale: `4px, 8px, 12px, 16px, 24px, 32px, 48px, 64px` (`p-1, p-2, p-3, p-4, p-6, p-8, p-12, p-16`). No arbitrary margin/padding values.

### Border Radius
- **Buttons, Form Inputs, Selects:** `12px–14px` (`rounded-xl`).
- **Cards, Panels, Tables:** `16px–20px` (`rounded-2xl`).
- **Modals, Dialogs, Previews:** `24px` (`rounded-3xl`).
- **Badges, Status Tags, Avatars:** `9999px` (`rounded-full`).

### Focus Rings
- All interactive controls require `focus-visible:outline focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-teal-600 dark:focus-visible:outline-teal-400`.
- Outline must never be removed or suppressed without visible keyboard replacement.

### Shadows
- Cards: `shadow-sm` (`box-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.05)`).
- Hover Elevate: `hover:shadow-md transition-shadow duration-150`.
- Modals & Floating Tools: `shadow-xl`.

---

## 5. Navigation & Layout Architectures

1. **`PublicLayout`:**
   - Sticky header with clean logo (`EduPlatform.`), public navigation links (Sign in, Register, Features), theme toggle, and main container.
   - Clean footer with copyright, security badges, and university accreditation notes.

2. **`AuthLayout`:**
   - Centered single-column card layout (`max-w-md`), brand mark, clear headline, status messages, and accessible form controls.

3. **`AppLayout` (Role-Aware):**
   - Header with brand mark, server-driven role badges (Teacher, Student, Owner, Admin), breadcrumbs, primary navigation links, user profile shortcut, theme toggle, and sign out button.
   - Mobile responsive navigation drawer.
   - Main content container with consistent max-widths (`max-w-7xl px-4 sm:px-6 lg:px-8 py-8`).

---

## 6. Shared Component Inventory

- **Layouts:** `PublicLayout`, `AuthLayout`, `AppLayout`.
- **Navigation:** `Breadcrumbs`, `Pagination`.
- **Typography & Structure:** `PageHeader`, `SectionHeader`.
- **Forms & Inputs:** `Button`, `FormField`, `SelectField`, `FileUpload`, `ConfirmDialog`.
- **Data Display:** `StatusBadge`, `DataTable`, `EmptyState`, `ErrorSummary`.
- **Educational & Workflow Domain:**
  - `WorkflowRail`: Visual 5-step stepper for PDF intake $\to$ Extraction $\to$ Lessons $\to$ Scenes $\to$ Review.
  - `LessonProgress`: Verified progress indicator with indeterminate and percentage states.
  - `PdfStatusList`: Per-PDF cards showing stage, status, error, and actions.
  - `LessonContent`: Bilingual lesson presenter with Arabic callouts and formula blocks.
  - `SceneViewport`: 3D simulator viewer container with full-screen, reload, and accessibility controls.

---

## 7. Status, Loading, Empty, and Error Patterns

- **Status Badges:** Text + Icon + Semantic Color. Never rely solely on color.
- **Empty States:** Clear icon/illustration + Title + Explanatory sentence + Primary Action Button.
- **Loading:** Non-layout-shifting skeletons or indeterminate progress bars.
- **Errors:** Friendly, non-technical explanation with recovery actions (Refresh, Retry, Return to Safety).
