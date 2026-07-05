# Antigravity Global Project Rules

- When the user asks to add or configure a new MCP Server or a new Skill, you **MUST** follow the protocols defined in [Skills-MCPs.md](file:///D:/projects/laravel_projects/college_project/.agents/Skills-MCPs.md).

## Enterprise Documentation & Specification Protocol

### 1. Document Architecture & Hierarchy
The documentation is structured in a strict hierarchy where higher-level documents serve as the absolute source of truth for lower-level documents:
* **Layer 1:** 01_ProjectVision.md (Vision, scope, target users)
* **Layer 2:** 02_Business.md (SaaS, ROI, compliance, deployment models)
* **Layer 3:** 03_SystemArchitecture.md (High-level components, ADRs)
* **Layer 4:** 04_Pipeline.md (12-stage AI pipeline flow details)
* **Layer 5:** 05_JSONContracts.md (JSON schemas on disk)
* **Layer 6:** 06_APISpecification.md (REST/LTI API specs)
* **Layer 7:** 07_DatabaseSpecification.md (Logical schemas, naming keys)
* **Layer 8:** 08_StateMachine.md (State lifecycle models)
* **Layer 9:** 09_QueueSpecification.md (Asynchronous queue rules)
* **Layer 10:** 10_ErrorHandling.md (Taxonomies, severity, recovery maps)
* **Layers 11-20:** Topic-specific specs (UISpecification, RBAC, etc.)

*Guidelines:*
* Higher layers always have priority. A lower-layer document MUST NOT contradict a higher-layer document.
* Never duplicate data across layers. Cross-reference other documents using standard links.
* Use RFC 2119 requirement language (MUST, MUST NOT, SHOULD, SHOULD NOT, MAY) for requirements.

### 2. Implementation-Agnostic Rules
* Specification documents (Layer 5 and below) MUST remain implementation-agnostic.
* Avoid vendor-specific syntax, database engines, server scripts, or programming code unless explicitly requested. Describe the logical architecture, rules, formats, and design constraints conceptually.

### 3. Step-by-Step Collaborative Workshop Workflow
When the user asks to modify, create, or work on any specification document:
1. **Explain the purpose** of the target document and what responsibilities belong (and do not belong) inside it.
2. **Propose an exhaustive Table of Contents** based on project guidelines and refinements.
3. **Conduct an architectural alignment discussion** to challenge constraints, evaluate risks, and list questions.
4. **Wait for explicit approval.** DO NOT generate or modify the target file until the user explicitly writes the single word: "Generate".

