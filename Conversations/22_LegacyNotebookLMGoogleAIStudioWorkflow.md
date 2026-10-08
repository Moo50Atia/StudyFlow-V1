# Legacy External-AI Workflow: NotebookLM to Google AI Studio

## 1. Document Purpose

This document records the current workflow used to transform educational material into interactive visual scenes through NotebookLM, prompt-driven processing, and Google AI Studio.

This is the current legacy workflow, not the final in-house architecture. It is documented so that:

- the existing process is not lost;
- current generated scenes can be reproduced;
- the strengths and weaknesses of the workflow are explicit;
- future KOS/KIE and simulation-runtime work can replace each external step safely;
- existing scene artifacts can be mapped to future internal contracts.

## 2. Workflow Status

| Item | Status |
|---|---|
| Workflow type | External AI-assisted authoring workflow |
| Primary research/content tool | NotebookLM |
| Prompt preparation | Manually maintained system prompts and staged instructions |
| Scene generation tool | Google AI Studio with Gemini |
| Final scene format | Generated HTML or React scene, depending on the experiment |
| Human review | Manual review and correction |
| Laravel integration | Optional or link-based; not the core of this workflow |
| Canonical source of truth | Uploaded educational material plus human review |
| Automation level | Semi-manual |
| Target replacement | In-house KOS/KIE, SimSpec compiler, and deterministic runtime |

## 3. Current End-to-End Flow

```text
Educational Material
        ↓
Upload to NotebookLM
        ↓
Structural Mapping Prompt
        ↓
Chapter / Mini-Chapter / Lesson Map
        ↓
Lesson-by-Lesson Iterative Prompt Workflow
        ↓
Educational Section Content
        ↓
Dynamic View Preparation
        ↓
Dynamic View Prompt
        ↓
Google AI Studio / Gemini
        ↓
Generated HTML or React Scene
        ↓
Manual Runtime Review
        ↓
Manual Correction and Iteration
        ↓
Stored Scene Artifact or External Link
```

## 4. Step 1: Material Upload to NotebookLM

The educational source is uploaded to NotebookLM. The material may be a textbook, lecture, PDF, or other course document.

NotebookLM is used as a source-grounded analysis environment. Its expected role is to keep the generated structure and explanations connected to the uploaded material.

### Inputs

- Textbook or lecture PDF.
- Lecture notes.
- Course material.
- Optional domain instructions such as medical, mathematical, physical, or chemical context.

### Expected properties

- The material remains the main factual reference.
- The AI should not freely invent unrelated curriculum content.
- The material is processed as a complete source, while the workflow later restricts processing to one lesson at a time.

### Current limitations

- Source spans and page coordinates are not automatically represented in the project's canonical KOS format.
- The output is dependent on NotebookLM's document parsing and context behavior.
- Reproducibility depends on the uploaded source, prompt, model behavior, and external tool state.
- There is no internal artifact manifest linking every generated scene field to a source fragment.

## 5. Step 2: Structural Curriculum Mapping

The first prompt asks the AI to act as a data architect and produce only a strict structural map.

The required hierarchy is:

```text
Chapter
  → Mini Chapter
      → Lesson
```

The purpose is to separate document structure from educational explanation.

The structural prompt explicitly instructs the AI:

- not to summarize;
- not to explain lessons;
- not to output educational data;
- to preserve logical relationships;
- to include every lesson;
- to treat a mini-chapter as a lesson when no lesson exists.

### Output

The expected output is a structural map only.

Example conceptual output:

```text
Chapter 1
  Mini-Chapter 1.1
    Lesson 1
    Lesson 2
  Mini-Chapter 1.2
    Lesson 3
```

### Human responsibility

The structure should be reviewed before proceeding because all later lesson processing depends on lesson order and boundaries.

### Future internal replacement

This step maps directly to the future KOS hierarchy:

```text
Document → Chapter → Mini-Chapter → Lesson → Section
```

The future implementation should store stable IDs, source pages, source spans, confidence, and approval status for each structural node.

## 6. Step 3: Lesson-by-Lesson Execution Protocol

After the structure is created, the workflow enters an iterative lesson-processing mode.

The protocol requires:

1. Process only the first lesson.
2. Apply the three educational/prompt stages to that lesson.
3. Stop after generating the final Dynamic View prompt.
4. End with:

```text
✅ Lesson [X] Completed. Awaiting 'Continue' for the next lesson.
```

5. Process the next lesson only when the user writes exactly:

```text
Continue
```

This prevents the model from reading ahead and mixing multiple lessons.

### Why this is valuable

- Reduces context mixing.
- Gives the user control over progression.
- Makes manual review possible after every lesson.
- Limits accidental generation of unrelated scenes.

### Future internal replacement

This should become a persisted pipeline state machine with:

- `current_lesson_id`;
- completed lesson records;
- approval status;
- input/output artifact hashes;
- retry count;
- prompt/model metadata;
- explicit resume operation.

## 7. Step 4: Educational Content Structuring

The first content prompt treats one lecture as a collection of independent learning sections.

For every section it requests:

- Section Identity.
- Core Understanding.
- Visual-First Explanation.
- Visual Scene Script.
- Dynamic View Blueprint.
- Visual Learning Outcome.
- Concept Check.
- Conceptual Summary.
- Egyptian Arabic explanation.
- Laws or formulas when applicable.
- Daily-life application.

The medical variant adds:

- Core Pathological Mechanism.
- Hemodynamic/tissue-level explanation.
- Clinical learning outcome.
- Diagnostic or pharmacological rules.
- Patient-case application.

### Current role of this step

This step converts retrieved/source-grounded material into a pedagogical representation suitable for scene planning.

### Current limitations

- The output is primarily formatted text rather than a strict typed content contract.
- Claims and formulas are not guaranteed to carry fragment-level citations.
- A visual blueprint may describe a scene without defining executable state rules.
- The distinction between source fact, explanation, analogy, and AI inference can be unclear.

### Future internal replacement

Generate structured content objects such as:

```json
{
  "section_id": "...",
  "claims": [],
  "formulas": [],
  "misconceptions": [],
  "examples": [],
  "visual_learning_outcome": "...",
  "source_fragment_ids": [],
  "approval_status": "DRAFT"
}
```

## 8. Step 5: Dynamic View Preparation

The second prompt is a preparation stage. It receives structured lesson sections and internally determines:

- Scene Type.
- Visual Objects.
- Animations.
- Overlays.
- User Interactions.
- Visual Learning Goal.

The prompt must not change, merge, split, or rephrase the educational content.

The expected output is a placeholder mapping:

```text
Lecture: <Lecture Name>

Lec<X> (Sec<Y>):
"<Dynamic View Prompt Placeholder>"
```

### Scene types used by the workflow

- Process.
- Comparison.
- Accumulation.
- Parallel Flow.
- Time Evolution.

### Purpose

This stage separates educational meaning from visual implementation planning.

### Future internal replacement

This stage should produce a typed scene plan containing:

- scene type;
- object registry;
- state variables;
- parameter candidates;
- interaction candidates;
- perspective candidates;
- misconception target;
- visual outcome;
- source evidence;
- unresolved design questions.

## 9. Step 6: Final Dynamic View Prompt Generation

The third prompt converts each prepared section into a concise executable-oriented prompt.

Every prompt must:

1. Start with one scene type.
2. Describe objects and actions.
3. Include overlays and annotations when necessary.
4. Include graphs or spatial visuals when appropriate.
5. Encode cause-and-effect animation.
6. Include optional user interaction.
7. End in a visual learning outcome.
8. Correct the misconception implied by the section.

The output format is:

```text
Lecture: <Lecture Name>

Lec<X> (Sec<Y>):
"<Executable Google Dynamic View Prompt>"
```

### Medical extension

Medical prompts may include a View Mode Toggle with up to three perspectives:

- Mechanical/Hemodynamic.
- Chemical/Pharmacological.
- Diagnostic/Imaging.
- Interventional/Surgical.

Medical interactions may include:

- stress-level sliders;
- drug-administration controls;
- procedural controls;
- diagnostic-view toggles.

## 10. Step 7: Google AI Studio Scene Generation

The final Dynamic View prompt is sent to Google AI Studio with a system instruction that asks Gemini to generate a complete interactive scene.

The current system instruction requires:

- exactly one self-contained React file or equivalent scene file;
- no introductory explanation;
- React functional components;
- React Three Fiber, Drei, Three.js, SVG, or Canvas depending on the scene;
- GSAP for smooth animation;
- KaTeX for mathematical formulas;
- Tailwind CSS for interface styling;
- Lucide icons;
- responsive glassmorphic UI;
- user-driven controls;
- free camera rotation where applicable;
- sliders and direct manipulation;
- failure states and extreme values;
- misconception correction;
- multiple perspectives when necessary.

### Required scene behavior

The generated scene should include:

- a full-screen visual environment;
- a learning title;
- a visual HUD;
- controls;
- an interactive canvas;
- live state values;
- a misconception corrector;
- a final learning-state message;
- responsive behavior;
- smooth state transitions.

### Current generation boundary

Google AI Studio currently receives a prose Dynamic View prompt and generates the implementation code. The generated file is therefore both:

- the visual presentation;
- the simulation logic;
- the interaction layer;
- the educational explanation layer.

This coupling is the main limitation of the legacy workflow.

## 11. Step 8: Manual Review and Iteration

After Google AI Studio generates a scene, the result is manually inspected.

Review areas include:

- Does the scene represent the educational concept correctly?
- Do user actions affect the correct variables?
- Does the scene show cause and effect?
- Are formulas correct?
- Are extreme values handled safely?
- Does the scene correct the intended misconception?
- Does the camera or layout hide important objects?
- Does the scene work on mobile screens?
- Does the generated code run without errors?
- Is the final visual state educationally meaningful?

### Current correction cycle

```text
Generated Scene
  ↓
Manual Inspection
  ↓
Identify Educational or Technical Problems
  ↓
Write a Revised Prompt
  ↓
Regenerate or Edit the Scene
  ↓
Review Again
```

## 12. Current Artifact Types

The current workflow may produce:

- structural lesson maps;
- lesson content documents;
- prepared Dynamic View mappings;
- final Dynamic View prompts;
- generated HTML scenes;
- generated React scene files;
- revised scene versions;
- comparison notes between generated implementations;
- manual improvement instructions.

The artifacts should be stored together per material and lesson, even before the future artifact registry exists.

Recommended legacy folder structure:

```text
Material/
  source/
  structure/
  lessons/
  dynamic-view-preparation/
  dynamic-view-prompts/
  scenes/
  review/
  versions/
```

## 13. Strengths of the Legacy Workflow

The current method already demonstrates several strong ideas:

- It separates structure extraction from content generation.
- It processes lessons incrementally.
- It allows human-controlled continuation.
- It uses source material as the grounding context.
- It distinguishes educational content from visual prompt preparation.
- It supports different domain prompt families, including medical education.
- It produces real interactive scenes rather than static explanations.
- It explores failure states and parameter boundaries.
- It supports multiple visual perspectives.
- It has already generated a large experimental scene collection.

## 14. Limitations of the Legacy Workflow

### 14.1 External dependency

The process depends on NotebookLM and Google AI Studio for important generation steps.

### 14.2 Weak reproducibility

The same prompt may not produce the same scene implementation across runs.

### 14.3 Prose-to-code coupling

The Dynamic View prompt is prose, and the final output is raw application code. There is no stable typed intermediate scene contract.

### 14.4 Incomplete provenance

The generated scene is not guaranteed to retain exact source fragment IDs for every formula, parameter, or rule.

### 14.5 Manual quality assurance

Correctness depends heavily on human inspection instead of automated simulation validation.

### 14.6 Inconsistent runtime architecture

Different generated scenes may use different UI structures, animation patterns, rendering engines, and state models.

### 14.7 Difficult maintenance

Fixing a shared behavior may require editing many individual generated files.

### 14.8 Security uncertainty

Generated code must be reviewed before execution because it may contain unsafe imports, network calls, DOM behavior, or arbitrary code paths.

## 15. Mapping to the Future In-House Architecture

| Legacy Step | Future In-House Replacement |
|---|---|
| NotebookLM source workspace | KOS source-document and provenance store |
| Structural mapping prompt | Schema-validated structure extraction stage |
| Continue-based lesson progression | Persisted pipeline state machine |
| Educational content prompt | KIE grounded content compiler |
| Dynamic View preparation | Typed scene-plan generator |
| Dynamic View prose prompt | SimSpec compiler input or scene-plan contract |
| Google AI Studio code generation | Controlled SimSpec generation and runtime compilation |
| Manual scene review | Automated validators plus educator approval |
| Generated HTML/React file | Versioned scene artifact compiled from SimSpec |
| External links | Internal scene registry and publication artifact |

## 16. Migration Strategy

The legacy workflow should remain usable while the in-house system is built.

### Phase 1: Preserve the legacy workflow

- Store every prompt and output.
- Store the source material used for each run.
- Record the model/tool used.
- Record the lesson and section identifiers.
- Preserve generated scene versions.
- Add manual review notes.

### Phase 2: Add structured intermediate outputs

Keep NotebookLM and Google AI Studio, but require them to produce:

- structure JSON;
- lesson JSON;
- scene-plan JSON;
- SimSpec draft JSON;
- provenance references.

### Phase 3: Replace the knowledge layer

Replace NotebookLM retrieval with KOS/KIE while preserving the same prompt stages as compatibility adapters.

### Phase 4: Replace code generation

Replace direct scene-code generation with:

```text
Evidence Package
  → SimSpec Compiler
  → Schema Validator
  → Deterministic Runtime
```

### Phase 5: Retain AI Studio as an optional authoring assistant

Google AI Studio may remain useful for:

- prototyping visual ideas;
- generating renderer adapters;
- experimenting with new scene types;
- creating non-authoritative presentation variants.

It should not remain the authoritative simulation runtime.

## 17. Relationship to KOS and KIE

The legacy workflow is an external front-end authoring process. KOS and KIE are the future internal knowledge foundation.

```text
Legacy Workflow:
NotebookLM → prompts → Google AI Studio → generated scene

Future Workflow:
KOS → KIE → evidence package → SimSpec → deterministic runtime
```

The legacy workflow should therefore be treated as:

- a current production experiment;
- a source of training examples and scene requirements;
- a benchmark for the future compiler;
- a temporary external adapter;
- not the final system architecture.

## 18. Required Legacy Metrics

For every generated scene, record:

- generation date;
- source material ID;
- lesson ID;
- section ID;
- structural map version;
- content prompt version;
- Dynamic View prompt version;
- Google AI Studio system-prompt version;
- model name/version when available;
- generated artifact hash;
- manual review status;
- known educational issues;
- known technical issues;
- final approval status.

## 19. Final Assessment

The current NotebookLM and Google AI Studio workflow is valid as a rapid experimentation and scene-prototyping method. It has already proven that the product can transform educational content into interactive visual scenes.

It is not sufficient as the long-term in-house architecture because it lacks deterministic intermediate contracts, complete provenance, centralized simulation logic, reusable runtime infrastructure, and automated validation.

The correct strategy is not to discard it. Preserve it as a legacy compatibility path while progressively replacing its internal responsibilities:

```text
NotebookLM retrieval → KOS/KIE
Prompt-based scene planning → typed scene plan
Gemini code generation → SimSpec compiler
Generated code behavior → deterministic runtime
Manual-only review → automated validation + educator approval
```
