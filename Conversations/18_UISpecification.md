# 18. User Interface & Screen Specifications

---

## 1. UI Design System & Design Tokens

### 1.1 Color Tokens
The interface MUST utilize a semantic HSL color palette to support accessible contrast ratios (minimum 4.5:1 for normal text) across both Light and Dark modes:
* **Primary (Brand):** Deep Teal (Light: `hsl(180, 60%, 25%)` | Dark: `hsl(180, 50%, 45%)`).
* **Secondary (Accents):** Slate Blue (Light: `hsl(215, 30%, 40%)` | Dark: `hsl(215, 20%, 65%)`).
* **Success:** Forest Green (Light: `hsl(145, 60%, 20%)` | Dark: `hsl(145, 50%, 45%)`).
* **Error:** Crimson Red (Light: `hsl(0, 70%, 30%)` | Dark: `hsl(0, 60%, 50%)`).
* **Warning:** Amber Gold (Light: `hsl(38, 75%, 25%)` | Dark: `hsl(38, 70%, 55%)`).
* **Neutral Backgrounds:** 
  * Light Mode: `hsl(210, 20%, 98%)` (Base), `hsl(0, 0%, 100%)` (Surface).
  * Dark Mode: `hsl(222, 15%, 10%)` (Base), `hsl(222, 12%, 15%)` (Surface).

### 1.2 Typography & Spacing Scale
* **Typography:** Core body text MUST utilize clean sans-serif layouts. Title elements MUST use structured weight sizes (Base: 16px, Headings: 20px, 24px, 32px).
* **Spacing:** Spacing increments MUST follow an 8px grid system (8px padding, 16px margins, 24px/32px grid layout tracks).

### 1.3 Elevation, Motion & Micro-Animations
* **Focus & Hover States:** Active hover items MUST transition color states within `150ms` using an ease-in-out curve.
* **Modals & Slide-ins:** Modals MUST scale in from `95%` to `100%` scale accompanied by a subtle backdrop blur.

### 1.4 Accessibility & RTL (Right-to-Left) Tokens
* **Screen Reader Access:** Interactive controls MUST include standard label mappings (`aria-labels`).
* **RTL Layouts:** Localized analogies (`arabic_explanation`) MUST automatically reverse container alignments to support RTL text flow.

---

## 2. Global Component Library

* **Inputs & Forms:** Standardized select boxes, sliders, and checkboxes with distinct focus outlines.
* **Feedback Indicators:** Loading spinners, progress cards, and warning logs.
* **Layout Blocks:** Expandable accordions, card grids, pagination controls, and tabs.

---

## 3. Navigation Architecture

* **Sidebar System Navigation:** Left-anchored collapsible sidebar containing routes filtered by active roles.
* **Breadcrumbs:** Context tracking links mapping parent pathways (e.g. `Home > Subjects > Electronics I > Lectures`).
* **Global Search:** Top header query bar executing filtering lookups.

---

## 4. UI State Catalog

* **Loading & Skeleton States:** Displays dummy layout cards while fetching data.
* **Empty & Data Missing States:** Informative panels with action triggers (e.g. "No Lectures Ingested Yet - Upload PDF").
* **Error & Offline States:** Friendly warning banners featuring the associated `correlation_id` and recovery suggestions.
* **Conflict & Locked States:** Displays a lock overlay with error codes (e.g. `412 Precondition Failed` edit collision notifications).
* **Unsaved Changes State:** Floating warning banners gating modifications inside the HIL Node Editor.

---

## 5. Interaction Patterns & Confirmations

* **Ingestion Wizard Steps:** Drag-and-drop file upload ──► select run mode ──► trigger run.
* **HIL Drag-and-Drop Tree Interaction:** Interactive tree mapping Chapters, Mini-Chapters, and Lessons. Nodes can be reordered using grab handles.
* **Confirmation Overlays:** Action dialogs requiring double-click or confirmation to delete, overwrite, or cancel active runs.

---

## 6. Form Design Standards

* **Validation Error Feedback:** High-contrast warning borders on input elements, accompanied by specific error text blocks underneath.
* **Autosave & Draft Handling:** Nodes Editor changes are saved in local draft state, displaying a "Saving Draft..." indicator in the header before confirming database persistence.

---

## 7. Notification & Feedback System

* **Toast Notifications:** Transient success cards (e.g., "Analogy Bookmarked!") sliding in at the top-right corner, auto-dismissing after 3 seconds.
* **Progress Modals:** Large modals detailing real-time pipeline run statuses with active progress bars.

---

## 8. Data Visualization Standards

* **Summary Cards:** Large metric displays (e.g. Active Students count, Token spend totals).
* **Timelines:** Visual progression steps mapping completed stages.
* **Cost Charts:** Multi-line charts detailing daily API token costs grouped by model categories.

---

## 9. Role-Based User Interfaces

### 9.1 Super Admin & IT Admin Interfaces
* **Admin Dashboard:** Access to tenant management tables, API billing charts, global queue utilization logs, and database metrics.
* **User Management:** Grid tables displaying users, email contacts, roles, and action buttons to modify permissions.

### 9.2 Teacher / Course Coordinator Interfaces
* **Teacher Course Center:** Subject lists assigned to the teacher. Clicking a course shows the ingestion status list and the "Upload Ingestion PDF" trigger.
* **HIL Node Editor Workspace:**
  ```
  ┌────────────────────────────────────────────────────────────────────────┐
  │ Lecture Node Editor [Unsaved Changes]             [Cancel] [Save & Pub]│
  ├────────────────────────────────────────────────────────────────────────┤
  │ Chapter 1: Operational Amplifiers                                      │
  │   ├── Lesson 1.1: Ideal Op-Amp Model (Pages 3-6)                 [Edit]│
  │   └── Lesson 1.2: Feedback Loop Analysis (Pages 7-10)            [Edit]│
  │   └── + Add New Lesson                                                 │
  └────────────────────────────────────────────────────────────────────────┘
  ```
  * Edit button opens inline inputs for Title, Page Start, and Page End parameters.

### 9.3 Student Interfaces
* **Split-Screen Player View:**
  * **Left Column (40% width):** Displays Core Concept, Formulas, and Conceptual Summaries.
  * **Right Column (60% width):** The interactive dynamic view canvas iframe.
* **Responsive Stacking Rule:** If viewport width is below `768px`, the split-screen layout stacks vertically: the text explanation panel displays at the top, and the interactive canvas panel is displayed below.
* **Collapsible Analogies Panel (Left Panel Bottom):**
  * Displays the colloquial Egyptian Arabic analogy box.
  * Controlled by the `STUDYFLOW_COLLOQUIAL_ENABLED` flag. Includes an toggle button to expand or collapse.
* **Check Overlays:** Modal checks that pop up before loading the canvas (pre-test) and after interaction completes (post-test).
