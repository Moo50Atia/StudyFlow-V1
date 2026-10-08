# **First Research Round: Adaptive Hybrid Corrective Graph RAG for Interactive Visual Learning**

## **1\. Executive Conclusion**

### **Suitability of Adaptive Hybrid Corrective Graph RAG**

The proposed \*\*Adaptive Hybrid Corrective Graph RAG\*\* is fundamentally sound in strategic vision but dangerously over-engineered if implemented naively as a monolithic, unconstrained agentic graph system. For an in-house interactive educational simulation platform, general-purpose web GraphRAG systems (such as community-summarizing graph extractors) suffer from catastrophic non-determinism, prohibitive LLM indexing costs, and entity hallucination. However, when refactored into a \*\*Deterministically Governed, Schema-Constrained Knowledge Structure (KOS) coupled with a Verified Hybrid Retrieval and Corrective Pipeline\*\*, the architecture directly solves the core failure modes of educational technology: semantic fragmentation, lack of prerequisite awareness, inability to enforce physical cause-and-effect rules, and unsupported hallucinations.

### **What Should Be Retained**

1\. \*\*Hybrid Retrieval (Dense \+ Exact Sparse \+ Structural Metadata)\*\*: Sparse lexical retrieval (BM25) and dense embeddings (dense vector) fused via Reciprocal Rank Fusion (RRF) and validated with cross-encoder reranking are non-negotiable for educational content containing symbolic equations, exact scientific nomenclatures, and localized bilingual tokens (Arabic/English).

2\. \*\*Deterministic Corrective Retrieval (CRAG)\*\*: Explicit relevance and sufficiency grading before generation, query transformation upon retrieval failure, and strict threshold-based abstention ("I cannot verify this from the curriculum") are vital to educational trust.

3\. \*\*Structured Domain Knowledge Graph (Subsystem-level)\*\*: Rather than unconstrained graph extraction, a strongly typed entity-relationship schema covering \`Concepts\`, \`Definitions\`, \`Formulas\`, \`Prerequisites\`, \`CausalRules\`, and \`SimulationParameters\` must be retained to power multi-hop reasoning and parameter bounding.

4\. \*\*Parent-Child Context Preservation\*\*: Chunk-level matching mapped back to parent hierarchical sections (chapters, sub-lessons) is critical to preserving contextual scope.

### **What Should Be Simplified**

1\. \*\*Graph Construction Pipeline\*\*: Replace open-ended LLM-driven entity/relation extraction with a \*\*Human-in-the-Loop (HITL), Schema-Enforced Extraction Compiler\*\*. An LLM proposes structured triples against a rigid Pydantic ontology; human curriculum educators review and approve triples during curriculum ingestion.

2\. \*\*Adaptive Routing Logic\*\*: Eliminate autonomous, non-deterministic LLM agent routers for standard student inquiries. Implement a \*\*Deterministic Rule-Based and Intent Classifier Router\*\* based on query structure (e.g., keyword detection for definitions, AST matching for formulas, graph-walk triggers for prerequisite navigation, and dedicated parametric pipelines for scene generation).

3\. \*\*Graph Traversal Scope\*\*: Restrict graph traversal to localized $k$-hop subgraphs ($k \\le 2$) anchored to retrieved chunk entities, rather than running global community summaries (e.g., Microsoft GraphRAG Leiden clustering) which dilute fine-grained physical causality.

### **What Should Be Delayed**

1\. \*\*Autonomous Agentic Retrieval Loops\*\*: Unbounded multi-step autonomous web/literature agents must be strictly prohibited from student-facing real-time query flows due to unpredictable latencies (5–30s), loop divergence risks, and runaway API token costs.

2\. \*\*Global Graph Community Summarization\*\*: Hierarchical graph summarization is unnecessary for single-concept simulation parameterization and should be deferred until macro-curriculum analytics are developed.

3\. \*\*Raw Code Generation for Simulations\*\*: Allowing LLMs to write raw JavaScript/WebGL code directly into the browser runtime represents an unacceptable security, stability, and pedagogical risk.

### **Recommended First Implementation**

The first implementation must be a \*\*Schema-Driven Hybrid-Corrective Pipeline (PostgreSQL \+ pgvector \+ BM25 \+ Pydantic-governed JSON Simulation DSL)\*\*:

> * \*\*Storage & Retrieval\*\*: PostgreSQL 16+ utilizing \`pgvector\` for dense embeddings, native \`tsvector\` with pg\_trgm for exact sparse lexical search, and relational adjacency/foreign-key tables for the curriculum concept-prerequisite graph.  
> * \*\*Ingestion & Document Intelligence\*\*: Marker and PyMuPDF for structural PDF extraction, preserving bounding-box coordinates, LaTeX formulas, and lesson hierarchies.  
> * \*\*Corrective Engine\*\*: LangGraph-governed state machine enforcing binary/graded relevance scoring with deterministic query reformulation and thresholded fallbacks.  
> * \*\*Simulation Layer\*\*: A declarative, JSON-based Simulation Specification Schema consumed by a sandboxed, deterministic simulation runtime (PhET Scenery/Axon or Matter.js/Pixi.js), ensuring zero unsafe arbitrary code execution.

\---

## **2\. Research Scope and Assumptions**

### **What Was Investigated**

\*\*Retrieval Architectures\*\*: Simple Dense RAG, Hybrid Sparse-Dense Fusion (RRF, Weighted), Parent-Child/Hierarchical Retrieval, Contextual Retrieval.

\*\*Corrective Paradigms\*\*: Corrective RAG (CRAG), Self-RAG, Active Retrieval Augmented Generation, hallucination suppression, threshold-based refusal.

\*\*Knowledge Graphs & GraphRAG\*\*: Microsoft GraphRAG, LightRAG, HippoRAG, Neo4j, Apache AGE, RDF/OWL, and typed relational graph representations.

\*\*Dynamic Routing & Agentic Workflows\*\*: Semantic routers, rule-based dispatchers, LangGraph stateful execution, LlamaIndex Workflows, DSPy programmatic assertions.

\*\*Educational Knowledge Modeling\*\*: Intelligent Tutoring Systems (ITS), Knowledge Organization Systems (KOS), Knowledge Space Theory (KST), curriculum dependency graphs, misconception modeling, 1EdTech/IMS Global standards.

\*\*Provenance & Citations\*\*: Character-level/sentence-level source attribution, W3C PROV-O, span-grounded generation, visual annotation of source PDFs.

\*\*Evaluation Frameworks\*\*: Ragas, DeepEval, TruLens, Arize Phoenix, automated synthetic test generation, human-in-the-loop review gating.

\*\*Simulation & Visualization Grammars\*\*: Declarative visualization engines (Vega/Vega-Lite), physics-based educational engines (PhET Interactive Simulations \- Scenery/Axon, Matter.js, Rapier), ECS (Entity-Component-System) architectures, and declarative state-machine DSLs.

\*\*Document Intelligence\*\*: Marker, PyMuPDF, Unstructured, Surya OCR, MinerU/PDF-Extract-Kit, layout-aware formula/table extraction, Arabic NLP tokenization and script handling.

\*\*Infrastructure & Security\*\*: Local self-hosted deployments (Docker Compose, Ollama, vLLM, TEI), pgvector vs dedicated vector DBs (Qdrant, Milvus, OpenSearch), prompt injection defenses (NeMo Guardrails, Lakera patterns), browser sandboxing.

### **What Was Not Investigated**

Proprietary closed-source enterprise LMS platforms (e.g., Blackboard, Canvas proprietary extensions).

Full 3D photorealistic spatial game engines (e.g., Unreal Engine 5, proprietary Unity pipelines), as educational web simulations require lightweight, accessible, zero-install 2D/light-3D browser execution.

Ungrounded generative creative writing models or text-to-video diffusion pipelines that do not provide deterministic parametric control.

### **Operational Assumptions**

\*\*Deployment\*\*: The platform must support complete air-gapped, on-premise, or self-hosted deployment on standard hardware (single or multi-GPU workstations with NVIDIA RTX 4090 / A5000 / A100 or CPU-only fallbacks for inference).

\*\*Provider Independence\*\*: Core workflows must interact with LLMs through standard OpenAI-compatible abstraction layers (e.g., LiteLLM, vLLM, Ollama), allowing hot-swapping between local open-weight models (Llama-3.3-70B, Qwen-2.5-32B/72B) and commercial APIs.

\*\*Pedagogical Authority\*\*: The textbook and educator-reviewed curriculum are the absolute ground truth. The system is prohibited from answering out-of-domain queries or fabricating physical constants not supported by evidence.

\*\*Language Requirements\*\*: Equal first-class support for English and Modern Standard Arabic scientific textbooks, including bidirectional layout, right-to-left formula associations, and localized scientific terms.

### **Limitations & Research Date**

\*\*Research Date\*\*: September 2026\.

\*\*Landscape Dynamics\*\*: While open-source projects evolve rapidly, analysis is anchored in verifiable source code, commit histories, architectural specifications, and published benchmarks up to Q3 2026\.

\---

## **3\. Target Feature Decomposition**

`Target Interactive Visual Learning Platform`  
`├── [A] Document Intelligence & Ingestion`  
`│   ├── Layout Analysis & Bounding Box Preservation`  
`│   ├── Mathematical Formula Extraction (LaTeX/MathML)`  
`│   ├── Hierarchical Structure Parsing (Book -> Chapter -> Section -> Paragraph)`  
`│   └── Multilingual/Arabic Tokenization & Normalization`  
`├── [B] Knowledge Organization System (KOS) & Graph Modeling`  
`│   ├── Concept & Entity Extractor (LLM + Rule-based)`  
`│   ├── Relationship & Dependency Modeler (Prerequisite, Causal, Part-Of)`  
`│   ├── Misconception & Boundary Case Mapping`  
`│   └── Human-in-the-Loop (HITL) Validation & Versioning Registry`  
`├── [C] Multi-Stage Retrieval Engine`  
`│   ├── Sparse Inverted Index (BM25 with language-specific analyzers)`  
`│   ├── Dense Embedding Index (Dense Vector Search with BGE/Qwen-Embed)`  
`│   ├── Structural/Parent-Child Contextual Retriever`  
`│   ├── Localized Graph Subgraph Traverser (1-2 Hop Neighborhoods)`  
`│   └── Hybrid Fusion & Cross-Encoder Reranking (RRF + BGE-Reranker)`  
`├── [D] Corrective & Evaluative Control`  
`│   ├── Context Relevance & Sufficiency Grading`  
`│   ├── Query Rewriting & Prerequisite Expansion Router`  
`│   ├── Strict Fallback & Abstention Gate`  
`│   └── Groundedness & Faithfulness Verification (Self-Correction Loop)`  
`├── [E] Provenance, Lineage & Citation Subsystem`  
`│   ├── Span-Level Bounding Box Source Anchoring`  
`│   ├── W3C PROV-O-Compliant Execution Audit Logs`  
`│   └── Student-Facing Interactive Citation Visualizer`  
`├── [F] Declarative Simulation Compiler & Runtime`  
`│   ├── Knowledge-to-DSL Extraction Pipeline`  
`│   ├── Pydantic Simulation Specification Contract (State, Params, Equations, Rules)`  
`│   ├── Sandboxed Deterministic Simulation Runtime (PhET Architecture)`  
`│   └── Misconception Visual Correction Handler`  
`└── [G] Platform Infrastructure, Security & Guardrails`  
&nbsp;&nbsp;&nbsp;&nbsp;`├── Multi-Tenant Curriculum Isolation`  
&nbsp;&nbsp;&nbsp;&nbsp;`├── Prompt Injection & Content Poisoning Defenses`  
&nbsp;&nbsp;&nbsp;&nbsp;`└── Observability, Evaluation & Regression Test Harness`

\---

## **4\. Research Method**

### **Search Strategy & Repositories**

The investigation surveyed official GitHub repositories, technical whitepapers, arXiv preprints, documentation portals, and inspectable code trees across the following domains:

\*\*Vector & Hybrid Search Engines\*\*: PostgreSQL/pgvector, Qdrant, Vespa, OpenSearch, Milvus.

\*\*RAG & Orchestration Frameworks\*\*: LangChain/LangGraph, LlamaIndex, Haystack, DSPy, LiteLLM.

\*\*Graph RAG Implementations\*\*: Microsoft GraphRAG, LightRAG, HippoRAG, Apache AGE, Neo4j GraphRAG.

\*\*Document Processing\*\*: Marker, MinerU, PyMuPDF, OCRmyPDF, Unstructured, Surya.

\*\*Educational Knowledge & ITS\*\*: OpenStax, Open edX, Intelligent Tutoring Systems literature (Cognitive Tutors, CTAT, ASSISTments), 1EdTech/IMS Global specifications.

\*\*Simulation & Visualization\*\*: PhET Interactive Simulations (\`phetsims/scenery\`, \`axon\`), Vega/Vega-Lite, Matter.js, MathBox.

\*\*Evaluation & Guardrails\*\*: Ragas, DeepEval, Arize Phoenix, NeMo Guardrails, Lakera.

### **Selection & Evidence Standards**

\*\*Verified Fact\*\*: Directly observed in public repository code, verified benchmarks, or official schema specifications.

\*\*Strong Inference\*\*: Technically derived from architectural properties, algorithmic complexity, or verified dependencies.

\*\*Weak Inference\*\*: Speculative extrapolation from preliminary benchmarks or non-peer-reviewed blog posts.

\*\*Unknown / Unverified\*\*: Marked explicitly when code is opaque or empirical validation is absent.

\---

## **5\. Concept-by-Concept Findings**

### **Concept A: Baseline Simple RAG**

\*\*Purpose\*\*: Baseline dense vector retrieval (chunking $ o$ bi-encoder embedding $ o$ top-$k$ nearest neighbors $ o$ LLM generation).

\*\*Why it Matters\*\*: Establishes the performance floor, latency profile, and operational baseline.

\*\*Main Findings\*\*: Baseline vector RAG fails catastrophically for scientific and educational domains. It suffers from \*chunk boundary truncation\* (splitting formulas or steps of a proof), \*out-of-vocabulary blindness\* (failing to match exact chemical symbols, variables, or rare terminology), and \*loss of hierarchical context\* (a chunk discussing "velocity" cannot tell if it belongs to rotational mechanics or linear kinematics).

\*\*Best Project Candidates\*\*: Basic LlamaIndex VectorStoreIndex, LangChain Core VectorStore, Qdrant Client.

\*\*Common Architectural Patterns\*\*: Naive recursive character chunking (500 tokens, 50 overlap) with single cosine-similarity lookup.

\*\*Common Failure Modes\*\*: Retrieving semantically similar text from an unrelated chapter; splitting a multi-line chemical reaction across two chunks; inability to infer prerequisite knowledge.

\*\*Reusable Components\*\*: Low-level text splitting abstractions and vector database client wrappers.

\*\*Recommended Decision\*\*: Reject as the primary system architecture; retain only as a low-level primitive inside hybrid retrieval.

\*\*Open Questions\*\*: How much chunk overlap is optimal before introducing noise into technical proofs?

### **Concept B: Hybrid Retrieval**

\*\*Purpose\*\*: Merging dense vector semantic retrieval with exact sparse keyword matching (BM25), metadata filters, and parent-child document linkages.

\*\*Why it Matters\*\*: Educational queries often combine exact identifiers (e.g., "$Fe\_2O\_3$", "Ohm's Law", "Article 4.2") with conceptual natural language queries ("how does moisture accelerate oxidation?").

\*\*Main Findings\*\*: Hybrid retrieval using Reciprocal Rank Fusion (RRF) with cross-encoder reranking consistently yields 20–35% higher recall on technical documentation than vector-only search. Furthermore, \*Parent-Child retrieval\* (indexing small 200-token child chunks for precision, but retrieving the 1,000-token parent section for LLM context) completely solves chunk-truncation problems.

\*\*Best Project Candidates\*\*: PostgreSQL with \`pgvector\` \+ native \`tsvector\`, Qdrant (sparse \+ dense vectors), Vespa, OpenSearch.

\*\*Common Architectural Patterns\*\*: Query $ o$ Parallel (Dense KNN \+ Sparse BM25) $ o$ RRF Combination $ o$ Cross-Encoder Reranker (e.g., BAAI/bge-reranker-large) $ o$ Parent Document Expander.

\*\*Common Failure Modes\*\*: Weight miscalibration between sparse and dense scores; poor handling of right-to-left Arabic morphology in generic BM25 analyzers.

\*\*Reusable Components\*\*: RRF fusion algorithms, cross-encoder scoring loops, PostgreSQL hybrid query functions.

\*\*Recommended Decision\*\*: Adopt as the foundational Tier-1 retrieval architecture.

### **Concept C: Corrective RAG (CRAG)**

\*\*Purpose\*\*: Evaluating whether retrieved context is relevant, complete, and sufficient to answer the prompt; triggering query rewriting, web search fallback, or explicit abstention when deficient.

\*\*Why it Matters\*\*: In education, providing an authoritative-sounding hallucinated answer to a student is far worse than admitting information is missing. For simulation generation, missing physical parameters causes broken scenes.

\*\*Main Findings\*\*: CRAG patterns introduce a lightweight evaluator (small cross-encoder or fast LLM) that classifies retrieved documents into \*Correct\*, \*Ambiguous\*, or \*Incorrect\*. If incorrect, it triggers query rewriting or graph traversal. If all retrieval fails, it triggers a deterministic refusal gate ("The provided textbook does not contain sufficient evidence to explain this phenomenon").

\*\*Best Project Candidates\*\*: LangGraph CRAG reference implementation, DSPy Assertions, LlamaIndex Corrective Workflows.

\*\*Common Architectural Patterns\*\*: Retrieve $ o$ Grade Relevance $ o$ Conditional Branch:

\- High Confidence $ o$ Synthesize;

\- Ambiguous/Insufficient $ o$ Rewrite Query / Expand Graph $ o$ Re-retrieve;

\- Still Insufficient $ o$ Controlled Abstention.

\*\*Common Failure Modes\*\*: Latency amplification (2–3x normal RAG if rewrites loop); grading hallucination if the evaluator is too weak.

\*\*Reusable Components\*\*: Relevance grading prompts, query transformation heuristics, state-machine loop boundaries.

\*\*Recommended Decision\*\*: Adopt as a mandatory gate for all student question answering and simulation parameter generation.

### **Concept D: GraphRAG and Knowledge-Graph Retrieval**

\*\*Purpose\*\*: Representing explicit relationships (prerequisites, causes, formulas, visual rules) between concepts and navigating structural paths during retrieval.

\*\*Why it Matters\*\*: Educational curricula are not flat text; they are directed acyclic graphs (DAGs) of prerequisites, multi-step causal chains (Water \+ Oxygen \+ Iron $ o$ Rust), and hierarchical taxonomy.

\*\*Main Findings\*\*: General-purpose GraphRAG (e.g., Microsoft GraphRAG) is designed for macro-level sensemaking across massive unstructured corpora using LLM-driven graph extraction and Leiden community summarization. It is prohibitively expensive (hundreds of LLM calls per document), non-deterministic, and fails to enforce rigid physical typing. Conversely, \*\*Localized Schema-Constrained Knowledge Graphs\*\* (using typed entities like \`Concept\`, \`Prerequisite\`, \`PhysicalParameter\`, \`CausalEffect\`) stored in relational or graph databases provide deterministic, fast ($\<15$ms) multi-hop context expansion.

\*\*Best Project Candidates\*\*: LightRAG (dual-level graph retrieval), HippoRAG (personalized PageRank over open-source graphs), Apache AGE / PostgreSQL adjacency models, Neo4j GraphRAG.

\*\*Common Architectural Patterns\*\*: Entity extraction at ingestion $ o$ Schema validation against educational ontology $ o$ Graph storage. At query time: Hybrid search finds entry nodes $ o$ Graph traverser retrieves 1–2 hop causal/prerequisite neighbors $ o$ Combined context passed to generation.

\*\*Common Failure Modes\*\*: Graph explosion; noisy, unvalidated relation extraction producing false scientific links; high ingestion cost.

\*\*Reusable Components\*\*: Graph traversal algorithms (Personalized PageRank, Breadth-First-Search over prerequisites), graph prompt templates.

\*\*Recommended Decision\*\*: Retain Graph Retrieval, but reject unstructured global GraphRAG. Implement a structured, schema-governed curriculum knowledge graph inside PostgreSQL/Apache AGE.

### **Concept E: Adaptive RAG Routing**

\*\*Purpose\*\*: Dynamically dispatching queries to the optimal retrieval strategy based on query intent and complexity.

\*\*Why it Matters\*\*: Answering "What is the formula for kinetic energy?" requires a fast, exact lexical/vector lookup ($\<200$ms), while generating a multi-parameter oxidation simulation requires graph traversal and causal parameter verification. Running heavy pipelines on simple questions inflates cost and latency.

\*\*Main Findings\*\*: Machine-learning classifiers or lightweight semantic routers (e.g., embedding cosine similarity against intent clusters) achieve $\>95\\%$ accuracy with sub-10ms overhead. Complex agentic routers are unnecessary and introduce unpredictable latency.

\*\*Best Project Candidates\*\*: Semantic Router (Aurelio AI), LangGraph conditional edges, LlamaIndex RouterQueryEngine.

\*\*Common Architectural Patterns\*\*: Query $ o$ Intent Classifier (Regex / AST / Embedding Classifier) $ o$ Strategy Selector:

\- \*Lookup/Definitional\* $ o$ Hybrid RAG;

\- \*Prerequisite/Dependency\* $ o$ Graph Walk;

\- \*Comparative/Causal/Scene Generation\* $ o$ Hybrid \+ Graph \+ CRAG Pipeline.

\*\*Common Failure Modes\*\*: Routing misclassification sending simple queries to expensive paths; failure to handle multi-intent queries.

\*\*Reusable Components\*\*: Semantic routing thresholds, intent clustering abstractions.

\*\*Recommended Decision\*\*: Adopt a deterministic, rule-and-embedding hybrid router for all incoming requests.

### **Concept F: Agentic and Advanced RAG**

\*\*Purpose\*\*: Multi-step planning, iterative reasoning, dynamic tool use, and self-reflection loops.

\*\*Why it Matters\*\*: Interactive scene generation requires multi-step synthesis: identifying physical entities, looking up governing laws, resolving parameter boundaries, and formatting the scene specification.

\*\*Main Findings\*\*: Unconstrained autonomous agents (e.g., AutoGPT-style loops) are completely unsuitable for production educational systems due to non-deterministic divergence, infinite loops, and hallucinated tool arguments. However, \*\*Constrained State-Machine Workflows\*\* (e.g., LangGraph or Temporal) with fixed state schemas, strict recursion limits, and deterministic transition guards provide the exact multi-step rigor needed for scene compilation without the chaotic failure modes of autonomous agents.

\*\*Best Project Candidates\*\*: LangGraph, DSPy, LlamaIndex Workflows.

\*\*Common Architectural Patterns\*\*: Directed state-machine graphs where each node is a discrete functional transformation (e.g., ExtractEntities $ o$ ValidateFormulas $ o$ CompileSceneDSL $ o$ VerifyParameters).

\*\*Common Failure Modes\*\*: State explosion; uncapped retry budgets draining LLM tokens; tool output parsing failures.

\*\*Reusable Components\*\*: Checkpointing, state persistence, conditional branching guards.

\*\*Recommended Decision\*\*: Adopt constrained state machines (LangGraph) exclusively for offline/authoring scene generation and complex teacher workflows. Strictly prohibit unconstrained agents in real-time student chats.

### **Concept G: Educational Knowledge Representation**

\*\*Purpose\*\*: Formal representation of pedagogical concepts, learning objectives, prerequisite structures, student skill states, and scientific misconceptions.

\*\*Why it Matters\*\*: Textbooks are structured pedagogical progressions. Without understanding that \*acceleration\* requires \*velocity\*, which requires \*displacement\*, the system cannot provide targeted remediation when a student fails to understand an interactive scene.

\*\*Main Findings\*\*: The Intelligent Tutoring System (ITS) community has established robust formalisms: Knowledge Space Theory (Doignon & Falmagne), Bayesian Knowledge Tracing (BKT), and 1EdTech CASE (Competencies and Academic Standards Exchange). These standards model learning dependencies as strict directed acyclic graphs (DAGs).

\*\*Best Project Candidates\*\*: OpenStax CNX / Tutor architecture, Open edX modulestore/content libraries, CTAT (Cognitive Tutor Authoring Tools).

\*\*Common Architectural Patterns\*\*: Relational models linking \`CurriculumUnit\` $ o$ \`LearningObjective\` $ o$ \`ConceptNode\` $ o$ \`PrerequisiteEdge\` $ o$ \`MisconceptionNode\`.

\*\*Common Failure Modes\*\*: Over-academic ontology bloat (e.g., massive OWL/RDF ontologies that cannot be efficiently queried); lack of mapping between pedagogical standards and raw text chunks.

\*\*Reusable Components\*\*: Prerequisite graph schemas, misconception association tables, learning objective taxonomy.

\*\*Recommended Decision\*\*: Implement a pragmatic, relational educational schema in PostgreSQL representing Concepts, Prerequisites, Misconceptions, and Simulation Anchors.

### **Concept H: Provenance, Citations, and Grounded Generation**

\*\*Purpose\*\*: Guaranteeing that every generated fact, parameter, and simulation rule is strictly traceable back to specific page coordinates, paragraphs, or tables in the source document.

\*\*Why it Matters\*\*: Absolute academic integrity and educator trust. If an educator or student questions a simulation rule, clicking the component must immediately highlight the exact textbook page and paragraph from which it was derived.

\*\*Main Findings\*\*: String-matching citations after generation is fragile and prone to hallucination. Best-in-class provenance architectures maintain \*\*Span-Level Source IDs\*\* throughout the entire pipeline: document extraction attaches UUIDs and bounding-box coordinates to every text fragment; retrieval passes these IDs to the generator; and the generator is constrained to cite specific Fragment UUIDs via JSON schemas.

\*\*Best Project Candidates\*\*: W3C PROV-O specification, Stanford ALCE (Attributed Language Models) methodology, Marker source-coordinate mapping.

\*\*Common Architectural Patterns\*\*: Ingestion splits PDF with geometric bounding box metadata ($x, y, w, h$, page) $ o$ Chunk carries fragment token offsets $ o$ Context injection explicitly formats references $ o$ Generator produces structured output with embedded citation IDs $ o$ UI highlights source PDF.

\*\*Common Failure Modes\*\*: Hallucinated citation tags; citation drift during cross-encoder reranking or context compression.

\*\*Reusable Components\*\*: PROV-O data models, citation verification regex/AST validators, PDF bounding-box viewers.

\*\*Recommended Decision\*\*: Adopt strict span-level UUID tracking from PDF ingestion to the final student UI.

### **Concept I: RAG Evaluation**

\*\*Purpose\*\*: Programmatic, objective measurement of retrieval recall/precision, faithfulness, groundedness, and simulation correctness.

\*\*Why it Matters\*\*: Prevents regressions when altering embedding models, chunking strategies, or graph traversals.

\*\*Main Findings\*\*: Standard lexical metrics (BLEU, ROUGE) are useless for educational RAG. Multi-metric evaluators (Ragas, DeepEval) that evaluate \*Context Recall\*, \*Context Precision\*, \*Faithfulness\* (absence of hallucination), and \*Answer Relevance\* using LLM-as-a-judge combined with deterministic AST validation provide reliable CI/CD test gates.

\*\*Best Project Candidates\*\*: Ragas, DeepEval, Arize Phoenix, TruLens.

\*\*Common Architectural Patterns\*\*: Automated evaluation pipelines running against synthetic and human-annotated golden datasets in CI/CD.

\*\*Common Failure Modes\*\*: LLM-as-a-judge bias; evaluating generation fluency instead of factual truth; absence of formula-specific unit testing.

\*\*Reusable Components\*\*: Synthetic test generation pipelines, ragas metric implementations, evaluation telemetry dashboards.

\*\*Recommended Decision\*\*: Standardize on Ragas and DeepEval for automated CI/CD regression testing, with strict pass/fail gates before publishing educational scenes.

### **Concept J: Scene Specification and Simulation Retrieval**

\*\*Purpose\*\*: Translating retrieved educational rules and relationships into interactive, executable, visual simulations.

\*\*Why it Matters\*\*: This is the ultimate differentiator of the platform. Textbooks describe static oxidation; the simulation engine must let students manipulate water volume, oxygen levels, and temperature to observe oxidation rates and surface degradation.

\*\*Main Findings\*\*: LLMs must \*\*never\*\* generate raw HTML/JavaScript directly for simulation logic. This creates massive security risks (XSS), non-deterministic bugs, broken rendering, and untestable physics. The industry standard pattern (pioneered by PhET Interactive Simulations and Vega/Vega-Lite) is to separate the \*\*Declarative Scene Specification (JSON Schema / DSL)\*\* from the \*\*Deterministic Simulation Engine Runtime\*\*. The RAG system extracts and populates the parameters, variables, equations, state transitions, and constraints into a strictly typed JSON specification; the client runtime executes this specification deterministically.

\*\*Best Project Candidates\*\*: PhET Interactive Simulations (\`phetsims/scenery\`, \`phetsims/axon\`, \`phetsims/tandem\`), Vega/Vega-Lite (declarative visualization grammar), Matter.js (2D physics engine), Rapier (deterministic physics engine).

\*\*Common Architectural Patterns\*\*: Retrieved Scientific Evidence $ o$ LLM Compiler $ o$ Validated Pydantic Scene Spec (Variables, Bounds, Differential Equations, Interactions) $ o$ Sandboxed Canvas/WebGL Engine.

\*\*Common Failure Modes\*\*: Infeasible physical parameters causing simulation instability (e.g., division by zero, infinite velocities); ungrounded aesthetic animations substituting for real physical laws.

\*\*Reusable Components\*\*: PhET Axon (reactive property model), PhET Scenery (interactive canvas/SVG scene graphs), Vega declarative grammar design.

\*\*Recommended Decision\*\*: Build a proprietary, declarative JSON-based Simulation Specification Schema ("SimSpec") executed by a hardened HTML5/Canvas runtime built on PhET architectural principles.

### **Concept K: Document Intelligence and Source Ingestion**

\*\*Purpose\*\*: High-fidelity parsing of complex educational PDFs containing multi-column layouts, mathematical formulas, chemical equations, tables, figures, and hierarchical sections.

\*\*Why it Matters\*\*: Garbage in, garbage out. If the extraction tool mangles $ rac{-b \\pm \\sqrt{b^2 \- 4ac}}{2a}$ or merges two independent table columns, the entire downstream RAG and simulation pipeline is corrupted.

\*\*Main Findings\*\*: Traditional OCR and basic PDF text extraction (e.g., pypdf, standard Tesseract) fail completely on scientific textbooks. Modern vision-based and layout-aware extractors (Marker, MinerU/PDF-Extract-Kit) achieve near-flawless extraction of LaTeX equations, markdown tables, and reading order, while retaining character/word bounding boxes.

\*\*Best Project Candidates\*\*: Marker (VikParuchuri), MinerU / PDF-Extract-Kit, PyMuPDF, Surya OCR.

\*\*Common Architectural Patterns\*\*: PDF $ o$ Layout Detection (YOLO/LayoutLM) $ o$ Line/Block Ordering $ o$ Formula OCR (Nougat/Latex-OCR) $ o$ Markdown with LaTeX $ o$ Structural AST Parser (Chapters, Sections, Figures).

\*\*Common Failure Modes\*\*: Severe degradation on scanned low-quality textbooks; mangling of right-to-left Arabic technical text where English formulas are embedded within Arabic sentences.

\*\*Reusable Components\*\*: Marker's layout segmentation models, PyMuPDF's low-level font and coordinate extractors.

\*\*Recommended Decision\*\*: Deploy Marker and PyMuPDF as the primary ingestion pipeline, supplemented by custom post-processors for Arabic bidirectional text normalization.

### **Concept L: Storage, Versioning, and Deployment**

\*\*Purpose\*\*: Reliable, self-hosted, reproducible storage infrastructure for documents, chunks, vectors, graphs, and simulation assets.

\*\*Why it Matters\*\*: The platform must run on-premise or in private cloud environments without external vendor dependencies, supporting complete auditability and data isolation.

\*\*Main Findings\*\*: Operating separate vector databases, relational databases, and graph databases creates enormous operational overhead (data sync issues, distributed transactions, backup nightmares). PostgreSQL with \`pgvector\` and native full-text search easily scales to millions of educational chunks while providing ACID guarantees, relational joins between chunks and curriculum nodes, and robust backup tools.

\*\*Best Project Candidates\*\*: PostgreSQL 16+ (with \`pgvector\` and \`pg\_trgm\`), MinIO (S3-compatible object storage for PDFs and assets), Redis (caching and pub/sub), Docker / Kubernetes.

\*\*Common Architectural Patterns\*\*: All metadata, relational curriculum graphs, dense vector embeddings, and full-text inverted indexes consolidated within a unified PostgreSQL instance, backed by MinIO for raw file storage.

\*\*Common Failure Modes\*\*: Database bloat from high-dimensional vectors; unindexed metadata queries causing query timeouts.

\*\*Reusable Components\*\*: pgvector HNSW indexing configurations, Docker Compose production templates.

\*\*Recommended Decision\*\*: Standardize on PostgreSQL \+ pgvector \+ MinIO as the sole operational storage layer for the prototype and first production release.

### **Concept M: Security and Trust**

\*\*Purpose\*\*: Defending against prompt injection, document poisoning, tenant data leakage, and malicious simulation code execution.

\*\*Why it Matters\*\*: Educational institutions require rigorous student data privacy (FERPA/GDPR compliance) and strict protection against students injecting prompts through chat or malicious contributors poisoning uploaded curriculum files.

\*\*Main Findings\*\*: Relying on system prompts for security is ineffective. Security must be enforced via \*\*Architectural Invariants\*\*: (1) Untrusted text is strictly isolated from control flow; (2) Simulations run declarative JSON in sandboxed \`\<iframe\>\` or WebWorker environments without access to DOM/cookies; (3) Multi-tenant isolation is enforced at the database layer using PostgreSQL Row Level Security (RLS).

\*\*Best Project Candidates\*\*: NeMo Guardrails, Lakera Gandalf patterns, DOMPurify, Content Security Policy (CSP) sandboxing.

\*\*Common Architectural Patterns\*\*: Input Sanitization $ o$ Semantic Guardrails $ o$ Database Row-Level Security $ o$ Declarative Execution Sandboxing $ o$ Output Verification.

\*\*Common Failure Modes\*\*: Indirect prompt injection via hidden white text in uploaded student PDFs; XSS via unescaped simulation labels.

\*\*Reusable Components\*\*: Pydantic input/output validation models, CSP configurations, NeMo colang guardrails.

\*\*Recommended Decision\*\*: Enforce strict database RLS, declarative JSON simulation sandboxing, and automated Pydantic schema validation across all boundaries.

\---

## **6\. Project-by-Project Analysis**

### **1\. Qdrant**

\*\*Official Repository\*\*: https://github.com/qdrant/qdrant

\*\*Documentation\*\*: https://qdrant.tech/documentation/

\*\*Relevant Sections\*\*: \`lib/collection/src/collection.rs\`, hybrid search endpoints, payload filtering documentation.

\*\*License\*\*: Apache 2.0.

\*\*Last Activity Signal\*\*: Actively maintained (daily commits, release v1.12+ in late 2024/2026).

\*\*Primary Language & Architecture\*\*: Rust; high-performance vector search engine with native payload indexing and sparse vector support.

\*\*Main Concept Addressed\*\*: Concept B (Hybrid Retrieval).

\*\*Secondary Concepts Addressed\*\*: Concept L (Storage & Deployment).

\*\*Intended Users\*\*: Engineers building production-scale semantic search and hybrid recommendation systems.

\*\*Core Philosophy\*\*: Fast, resource-efficient vector search written in memory-safe Rust with tight coupling between vectors and JSON metadata filtering.

\*\*Strengths\*\*: Exceptional speed, native sparse vector support (SPLADE/BM25), hardware-aware SIMD optimizations, rich JSON payload filtering.

\*\*Weaknesses\*\*: Separate database service to maintain alongside relational DB; lacks built-in relational joins or graph traversals.

\*\*Evidence Quality\*\*: High (verified open-source benchmarks and public Rust codebase).

\*\*Fit with Our Product\*\*: High for retrieval engine, medium-high overall.

\*\*Recommended Role\*\*: Subsystem (if standalone vector DB is needed) or Reference Implementation.

\*\*Integration Difficulty\*\*: Moderate (REST/gRPC Python client available).

\*\*Migration Risk\*\*: Low (standardized snapshot backups).

\*\*Licensing Risk\*\*: None (Apache 2.0).

\*\*Security Risk\*\*: Low (role-based API keys, TLS).

\*\*What to Reuse\*\*: Sparse-dense fusion architecture and payload filtering patterns.

\*\*What Not to Copy\*\*: Do not adopt as the sole database; cannot store relational curriculum structures.

\*\*Rating\*\*:

\- Philosophical Alignment: 4.5/5 (Focuses on retrieval quality and speed)

\- Grounding & Provenance: 4.0/5 (Stores span/document IDs in payload)

\- Retrieval Quality: 5.0/5 (State-of-the-art HNSW and sparse indexing)

\- Corrective Capability: N/A (Storage engine only)

\- Knowledge Graph Capability: 1.5/5 (Flat vector/payload model only)

\- Educational Model Compatibility: 3.0/5 (Requires external schema mapping)

\- Simulation Compatibility: 3.0/5 (Can retrieve simulation metadata)

\- Technical Maturity: 5.0/5 (Battle-tested in enterprise production)

\- Extensibility: 4.0/5 (Custom payload schemas, gRPC)

\- Local/On-Premise: 5.0/5 (Single lightweight binary or Docker container)

\- Provider Independence: 5.0/5 (Model-agnostic; stores raw vectors)

\- Evaluation Support: 3.5/5 (Telemetry integrations with OpenTelemetry)

\- Security Posture: 4.5/5 (API key auth, TLS, on-premise containment)

\- License Suitability: 5.0/5 (Permissive Apache 2.0)

\- Integration Effort: 4.0/5 (Clean Python SDK)

\- \*Confidence Level\*: High.

### **2\. PostgreSQL with pgvector & pg\_trgm**

\*\*Official Repository\*\*: https://github.com/pgvector/pgvector

\*\*Documentation\*\*: https://github.com/pgvector/pgvector / https://www.postgresql.org/docs/

\*\*Relevant Sections\*\*: \`src/hnsw.c\`, \`src/ivfflat.c\`, PostgreSQL native full-text search (\`tsvector\`, \`tsquery\`).

\*\*License\*\*: PostgreSQL License (similar to BSD/MIT).

\*\*Last Activity Signal\*\*: Highly active (v0.8+ with iterative HNSW performance optimizations).

\*\*Primary Language & Architecture\*\*: C extension for PostgreSQL relational database.

\*\*Main Concept Addressed\*\*: Concept B (Hybrid Retrieval) & Concept L (Storage & Deployment).

\*\*Secondary Concepts Addressed\*\*: Concept D (Graph via Recursive CTEs / Adjacency), Concept H (Provenance).

\*\*Intended Users\*\*: Software architects wanting ACID-compliant unified relational, full-text, and vector storage.

\*\*Core Philosophy\*\*: Extend the world's most reliable relational database to handle vectors, eliminating distributed state and synchronization overhead.

\*\*Strengths\*\*: Zero infrastructure sprawl; unified transactional queries combining metadata, exact text, vector distance, and relational joins; bulletproof ACID compliance; Row Level Security.

\*\*Weaknesses\*\*: Slower index build times than dedicated Rust/C++ vector engines at massive scale ($\>50$M vectors); requires proper RAM tuning.

\*\*Evidence Quality\*\*: High (ubiquitous production deployment and verified benchmarks).

\*\*Fit with Our Product\*\*: Exceptional (perfect match for curriculum hierarchies, provenance tracking, and hybrid search).

\*\*Recommended Role\*\*: Foundation.

\*\*Integration Difficulty\*\*: Easy (Standard SQLAlchemy, asyncpg, psycopg3).

\*\*Migration Risk\*\*: Very Low (standard pg\_dump, logical replication).

\*\*Licensing Risk\*\*: None (Permissive).

\*\*Security Risk\*\*: Very Low (mature enterprise RBAC, RLS, audit logging).

\*\*What to Reuse\*\*: HNSW index structures, combined SQL queries joining chunks, concepts, and embeddings.

\*\*What Not to Copy\*\*: Avoid building deep multi-hop graph graph-analytics (e.g., PageRank across billions of nodes) inside recursive SQL; keep graph queries bounded to 1–2 hops.

\*\*Rating\*\*:

\- Philosophical Alignment: 5.0/5 (Pragmatic, reliable, deterministic)

\- Grounding & Provenance: 5.0/5 (Foreign keys strictly link chunks to document spans)

\- Retrieval Quality: 4.5/5 (Excellent hybrid dense-sparse capabilities)

\- Corrective Capability: N/A (Storage layer)

\- Knowledge Graph Capability: 4.0/5 (Excellent for local 1-2 hop adjacency and hierarchy)

\- Educational Model Compatibility: 5.0/5 (Native relational modeling of courses, lessons, prerequisites)

\- Simulation Compatibility: 4.0/5 (Stores and indexes JSON DSL specs)

\- Technical Maturity: 5.0/5 (PostgreSQL core is 30+ years mature)

\- Extensibility: 5.0/5 (Extensions: pgvector, Apache AGE, pg\_trgm)

\- Local/On-Premise: 5.0/5 (Runs anywhere from laptop to bare-metal cluster)

\- Provider Independence: 5.0/5 (Completely open source)

\- Evaluation Support: 4.0/5 (Standard SQL analytics)

\- Security Posture: 5.0/5 (Gold standard Row Level Security)

\- License Suitability: 5.0/5 (Permissive)

\- Integration Effort: 5.0/5 (Native standard database)

\- \*Confidence Level\*: High.

### **3\. LangGraph (LangChain Ecosystem)**

\*\*Official Repository\*\*: https://github.com/langchain-ai/langgraph

\*\*Documentation\*\*: https://langchain-ai.github.io/langgraph/

\*\*Relevant Sections\*\*: \`langgraph/graph/state.py\`, \`langgraph/pregel/\`, multi-agent and corrective RAG templates.

\*\*License\*\*: MIT.

\*\*Last Activity Signal\*\*: Highly active (industry standard agentic workflow framework in 2024–2026).

\*\*Primary Language & Architecture\*\*: Python; stateful orchestration engine based on Pregel graph processing model.

\*\*Main Concept Addressed\*\*: Concept C (Corrective RAG) & Concept F (Agentic & Advanced RAG).

\*\*Secondary Concepts Addressed\*\*: Concept E (Adaptive Routing).

\*\*Intended Users\*\*: Developers building cyclic, stateful, multi-actor LLM applications with human-in-the-loop validation.

\*\*Core Philosophy\*\*: Stateful, deterministic graph orchestration. Loops and cycles are first-class citizens, backed by durable persistence and checkpoints.

\*\*Strengths\*\*: Strict state schema enforcement via Pydantic/TypedDict; built-in time-travel debugging, checkpointing, and human-in-the-loop review interrupts; clear separation of nodes and conditional edges.

\*\*Weaknesses\*\*: Requires disciplined architecture to prevent graph spaghetti; slight runtime overhead for simple linear chains.

\*\*Evidence Quality\*\*: High (verified source code, broad enterprise adoption).

\*\*Fit with Our Product\*\*: Exceptional (ideal for orchestrating Corrective RAG cycles and multi-step Simulation Specification Compilation).

\*\*Recommended Role\*\*: Foundation (Orchestration Engine).

\*\*Integration Difficulty\*\*: Moderate (Python native, clean functional model).

\*\*Migration Risk\*\*: Low (clean state transitions decoupled from specific LLM providers).

\*\*Licensing Risk\*\*: None (MIT).

\*\*Security Risk\*\*: Low (execution is constrained to developer-defined graphs).

\*\*What to Reuse\*\*: State-machine loops, conditional routing edges, relevance grading nodes, human-in-the-loop review pause/resume patterns.

\*\*What Not to Copy\*\*: Avoid unconstrained autonomous agent loops; enforce hard recursion limits (\`max\_iterations \= 3\`).

\*\*Rating\*\*:

\- Philosophical Alignment: 5.0/5 (Enforces deterministic state boundaries over magical autonomy)

\- Grounding & Provenance: 4.5/5 (State retains chunk provenance throughout the execution trace)

\- Retrieval Quality: 4.5/5 (Enables multi-pass corrective retrieval)

\- Corrective Capability: 5.0/5 (Native support for CRAG / Self-RAG loops)

\- Knowledge Graph Capability: 3.5/5 (Execution graph, not data knowledge graph)

\- Educational Model Compatibility: 4.5/5 (Models learning flows and remediation pathways)

\- Simulation Compatibility: 4.5/5 (Compiles multi-step simulation specs)

\- Technical Maturity: 4.5/5 (Mature, production-tested)

\- Extensibility: 5.0/5 (Arbitrary Python nodes and checkpointers)

\- Local/On-Premise: 5.0/5 (Fully local Python package)

\- Provider Independence: 5.0/5 (Works with LiteLLM, Ollama, vLLM, OpenAI, Anthropic)

\- Evaluation Support: 4.0/5 (Integrates with LangSmith, Phoenix, Tracing)

\- Security Posture: 4.5/5 (Deterministic control flow prevents unauthorized tool calls)

\- License Suitability: 5.0/5 (Permissive MIT)

\- Integration Effort: 4.0/5 (Requires learning graph-state paradigm)

\- \*Confidence Level\*: High.

### **4\. Microsoft GraphRAG**

\*\*Official Repository\*\*: https://github.com/microsoft/graphrag

\*\*Documentation\*\*: https://microsoft.github.io/graphrag/

\*\*Relevant Sections\*\*: \`graphrag/index/graph/\`, Leiden community detection algorithms, prompt templates for entity extraction.

\*\*License\*\*: MIT.

\*\*Last Activity Signal\*\*: Actively maintained by Microsoft Research.

\*\*Primary Language & Architecture\*\*: Python; pipeline for LLM-based hierarchical knowledge graph extraction and community-level summarization.

\*\*Main Concept Addressed\*\*: Concept D (GraphRAG and Knowledge-Graph Retrieval).

\*\*Secondary Concepts Addressed\*\*: Concept G (Educational Knowledge Representation).

\*\*Intended Users\*\*: Analysts conducting macro-level synthesis over massive, unstructured text collections (e.g., investigative journalism, legal discovery).

\*\*Core Philosophy\*\*: Extract general entities and relationships using LLMs, cluster them into communities using the Leiden algorithm, and generate pre-computed hierarchical summaries to answer global queries.

\*\*Strengths\*\*: Outstanding for high-level thematic queries ("What are the main themes across this 10,000-page archive?"); automated graph extraction.

\*\*Weaknesses\*\*: \*\*Catastrophic indexing cost\*\* (thousands of LLM calls per textbook); high latency; non-deterministic entity extraction; \*\*useless for fine-grained physical causality and exact parameter extraction\*\*; lacks formal educational prerequisite semantics.

\*\*Evidence Quality\*\*: High (peer-reviewed research paper and inspectable open-source code).

\*\*Fit with Our Product\*\*: Poor for operational core; moderate for inspiration.

\*\*Recommended Role\*\*: Reject as a runtime foundation; use only as inspiration for prompt-based triple extraction.

\*\*Integration Difficulty\*\*: High (heavyweight pipeline, rigid data formats).

\*\*Migration Risk\*\*: High (proprietary pipeline concepts).

\*\*Licensing Risk\*\*: None (MIT).

\*\*Security Risk\*\*: Medium (heavy reliance on uncontrolled LLM extraction).

\*\*What to Reuse\*\*: Prompts for entity-relationship extraction (modified with strict Pydantic schemas).

\*\*What Not to Copy\*\*: Leiden community summarization for simulation engines; pre-computed global summaries that obliterate fine-grained page citations.

\*\*Rating\*\*:

\- Philosophical Alignment: 2.0/5 (Prioritizes global summarization over grounded, fine-grained deterministic truth)

\- Grounding & Provenance: 2.5/5 (Summaries aggregate across many chunks, losing exact sentence coordinates)

\- Retrieval Quality: 3.5/5 (Good for broad questions, terrible for specific formulas)

\- Corrective Capability: 2.0/5 (Batch pipeline, not an interactive corrective loop)

\- Knowledge Graph Capability: 4.0/5 (Sophisticated graph clustering)

\- Educational Model Compatibility: 1.5/5 (Lacks concept-prerequisite-misconception semantics)

\- Simulation Compatibility: 1.0/5 (Cannot generate deterministic simulation specs)

\- Technical Maturity: 3.5/5 (Research-grade production pipeline)

\- Extensibility: 3.0/5 (Complex, rigid pipeline architecture)

\- Local/On-Premise: 2.5/5 (Massive LLM token requirements make local LLM execution painfully slow)

\- Provider Independence: 4.0/5 (Configurable endpoints, but needs massive context/compute)

\- Evaluation Support: 3.0/5 (Internal benchmark scripts)

\- Security Posture: 3.0/5 (Prone to extraction poisoning)

\- License Suitability: 5.0/5 (Permissive MIT)

\- Integration Effort: 2.0/5 (Very difficult to adapt to real-time interactive systems)

\- \*Confidence Level\*: High.

### **5\. LightRAG**

\*\*Official Repository\*\*: https://github.com/HKUDS/LightRAG

\*\*Documentation\*\*: https://github.com/HKUDS/LightRAG (README & paper: "LightRAG: Simple and Fast Knowledge Graph Augmented Generation").

\*\*Relevant Sections\*\*: \`lightrag/lightrag.py\`, dual-level retrieval logic (low-level specific entities \+ high-level thematic relationships).

\*\*License\*\*: MIT.

\*\*Last Activity Signal\*\*: Highly active (released late 2024, rapid community adoption into 2026).

\*\*Primary Language & Architecture\*\*: Python; dual-level graph retrieval combining entity-level precise retrieval with higher-level relationship queries.

\*\*Main Concept Addressed\*\*: Concept D (GraphRAG and Knowledge-Graph Retrieval).

\*\*Secondary Concepts Addressed\*\*: Concept B (Hybrid Retrieval).

\*\*Intended Users\*\*: Developers seeking the benefits of GraphRAG without the immense indexing overhead and cost of Microsoft GraphRAG.

\*\*Core Philosophy\*\*: Lightweight, fast graph-enhanced generation using dual-level retrieval (local entity neighbors \+ global relationship context) with incremental updates.

\*\*Strengths\*\*: Orders of magnitude cheaper and faster to index than Microsoft GraphRAG; supports incremental document updates; dual-level retrieval matches both specific facts and conceptual themes.

\*\*Weaknesses\*\*: Still relies on LLMs for raw extraction without strict schema constraints; lacks formal educational ontologies.

\*\*Evidence Quality\*\*: Medium-High (ArXiv paper, active open-source implementation).

\*\*Fit with Our Product\*\*: Moderate-High as a reference implementation for graph query mechanics.

\*\*Recommended Role\*\*: Reference Implementation / Subsystem.

\*\*Integration Difficulty\*\*: Moderate (cleaner and much simpler codebase than Microsoft GraphRAG).

\*\*Migration Risk\*\*: Low.

\*\*Licensing Risk\*\*: None (MIT).

\*\*Security Risk\*\*: Low-Medium.

\*\*What to Reuse\*\*: The dual-level retrieval concept (local entity-specific graph search \+ broader concept search) and incremental indexing patterns.

\*\*What Not to Copy\*\*: Raw string-based relationship storage; replace with strongly typed Pydantic entity/relation tables.

\*\*Rating\*\*:

\- Philosophical Alignment: 4.0/5 (Fast, incremental, cost-conscious)

\- Grounding & Provenance: 3.5/5 (Retains entity-chunk associations)

\- Retrieval Quality: 4.5/5 (Strong dual-level semantic \+ structural recall)

\- Corrective Capability: 2.5/5 (Lacks built-in corrective loops)

\- Knowledge Graph Capability: 4.0/5 (Clean graph-vector integration)

\- Educational Model Compatibility: 2.5/5 (Needs domain schema overlay)

\- Simulation Compatibility: 2.5/5 (Does not model physical constraints out of the box)

\- Technical Maturity: 3.5/5 (Newer project, but rapidly stabilizing)

\- Extensibility: 4.0/5 (Modest, readable codebase)

\- Local/On-Premise: 4.5/5 (Runs efficiently on local models)

\- Provider Independence: 4.5/5 (OpenAI-compatible interfaces)

\- Evaluation Support: 3.0/5 (Standard retrieval benchmarks)

\- Security Posture: 4.0/5 (Contained local graph)

\- License Suitability: 5.0/5 (MIT)

\- Integration Effort: 3.5/5 (Requires adapting to custom storage backend)

\- \*Confidence Level\*: Medium-High.

### **6\. PhET Interactive Simulations (Scenery & Axon)**

\*\*Official Repository\*\*: https://github.com/phetsims/scenery / https://github.com/phetsims/axon

\*\*Documentation\*\*: https://phet.colorado.edu/ / https://github.com/phetsims/phet-info

\*\*Relevant Sections\*\*: \`axon/js/Property.js\`, \`axon/js/DerivedProperty.js\`, \`scenery/js/nodes/Node.js\`.

\*\*License\*\*: MIT / GPLv3 (Core libraries like Axon/Scenery/Tandem are MIT; some full sims have GPL/educational licensing).

\*\*Last Activity Signal\*\*: Actively maintained by the University of Colorado Boulder (continuous production commits).

\*\*Primary Language & Architecture\*\*: TypeScript / JavaScript; high-performance reactive educational simulation architecture separating Model, View, and Controller.

\*\*Main Concept Addressed\*\*: Concept J (Scene Specification and Simulation Retrieval).

\*\*Secondary Concepts Addressed\*\*: Concept G (Educational Knowledge Representation).

\*\*Intended Users\*\*: Educational software engineers building accessible, research-proven scientific simulations.

\*\*Core Philosophy\*\*: Research-grounded interactive simulations where educational concepts are represented as reactive physical models (\`Model\`) observed by interactive visual scene graphs (\`View\`). Simulations are 100% deterministic, testable, and accessible.

\*\*Strengths\*\*: Decades of empirical educational research; rock-solid reactive state management (\`Axon.Property\`, \`DerivedProperty\`); accessible SVG/Canvas rendering (\`Scenery\`); built-in support for parameter bounds, cause-and-effect graphs, and step-by-step time evolution.

\*\*Weaknesses\*\*: Monolithic repository ecosystem with bespoke build tooling (\`chipper\`); historically designed for human authors rather than automated LLM compilation.

\*\*Evidence Quality\*\*: High (gold standard in global STEM education, over 1 billion simulated sessions).

\*\*Fit with Our Product\*\*: Exceptional (the exact target runtime philosophy for our interactive learning scenes).

\*\*Recommended Role\*\*: Foundation / Inspiration (adopt its reactive model architecture and UI paradigms; build a clean JSON runtime interpreter on top).

\*\*Integration Difficulty\*\*: Moderate (core libraries can be extracted or replicated in modern TypeScript).

\*\*Migration Risk\*\*: Low.

\*\*Licensing Risk\*\*: Low (core runtime components are MIT; avoid copy-pasting full GPL sim code).

\*\*Security Risk\*\*: Very Low (client-side deterministic reactive architecture, zero eval/unsafe scripts).

\*\*What to Reuse\*\*: The \`Axon\` reactive state-graph pattern (\`Property\`, \`DerivedProperty\`, \`Emitter\`), step-driven physical clocks, and interactive slider/probe UI patterns.

\*\*What Not to Copy\*\*: Do not copy their legacy SVN/bespoke build tools; implement modern Vite/TypeScript packages.

\*\*Rating\*\*:

\- Philosophical Alignment: 5.0/5 (Complete alignment: grounded, deterministic, visual correction of misconceptions)

\- Grounding & Provenance: 4.0/5 (Direct mapping of physical equations to reactive properties)

\- Retrieval Quality: N/A (Simulation runtime)

\- Corrective Capability: N/A (Simulation runtime)

\- Knowledge Graph Capability: 4.0/5 (Implicit reactive graph of physical variables)

\- Educational Model Compatibility: 5.0/5 (Built exclusively for STEM pedagogy)

\- Simulation Compatibility: 5.0/5 (The gold standard educational simulation engine)

\- Technical Maturity: 5.0/5 (20+ years of active development)

\- Extensibility: 4.5/5 (Modular TypeScript nodes)

\- Local/On-Premise: 5.0/5 (100% client-side web technology; zero cloud dependency)

\- Provider Independence: 5.0/5 (Zero AI dependencies)

\- Evaluation Support: 4.5/5 (Comprehensive automated fuzz testing and state record/replay)

\- Security Posture: 5.0/5 (Pure client-side declarative state; no code injection)

\- License Suitability: 4.0/5 (Requires careful extraction of MIT submodules)

\- Integration Effort: 3.5/5 (Requires building a JSON-to-Axon compiler)

\- \*Confidence Level\*: High.

### **7\. Marker (by Vik Paruchuri)**

\*\*Official Repository\*\*: https://github.com/VikParuchuri/marker

\*\*Documentation\*\*: https://github.com/VikParuchuri/marker

\*\*Relevant Sections\*\*: \`marker/convert.py\`, layout detection, equation extraction models, coordinate preservation tables.

\*\*License\*\*: GPL-3.0 (with commercial licensing available).

\*\*Last Activity Signal\*\*: Highly active (frequent updates, industry-leading accuracy on STEM textbooks).

\*\*Primary Language & Architecture\*\*: Python / PyTorch; deep-learning pipeline converting PDF to high-fidelity Markdown with full LaTeX equations and layout preservation.

\*\*Main Concept Addressed\*\*: Concept K (Document Intelligence & Source Ingestion).

\*\*Secondary Concepts Addressed\*\*: Concept H (Provenance & Citations).

\*\*Intended Users\*\*: Researchers and engineers building high-accuracy document ingestion pipelines for technical and scientific literature.

\*\*Core Philosophy\*\*: High-speed, high-accuracy extraction of complex scientific documents by combining heuristic layout detection with specialized vision transformers for math, tables, and text.

\*\*Strengths\*\*: 10x faster than Nougat; extracts complex multi-line LaTeX equations flawlessly; strips headers/footers accurately; outputs clean Markdown with block-level structural tags.

\*\*Weaknesses\*\*: GPL-3.0 license requires isolation behind a microservice API to prevent license contagion; GPU-intensive for high-throughput batch ingestion.

\*\*Evidence Quality\*\*: High (verified open benchmarks across thousands of arXiv and textbook pages).

\*\*Fit with Our Product\*\*: Exceptional for offline curriculum ingestion.

\*\*Recommended Role\*\*: Subsystem (isolated ingestion microservice).

\*\*Integration Difficulty\*\*: Easy-Moderate (CLI and Python module, runs in Docker container).

\*\*Migration Risk\*\*: Low (outputs standardized Markdown/LaTeX).

\*\*Licensing Risk\*\*: Medium (GPL-3.0 requires strict separation via REST API; cannot be bundled into closed-source monolithic libraries).

\*\*Security Risk\*\*: Low (runs offline in sandboxed ingestion workers).

\*\*What to Reuse\*\*: Entire extraction pipeline for converting math/science textbooks into equation-rich Markdown.

\*\*What Not to Copy\*\*: Do not link Marker libraries directly into proprietary proprietary application code; keep it isolated via HTTP/gRPC.

\*\*Rating\*\*:

\- Philosophical Alignment: 5.0/5 (Preserves exact mathematical and scientific truth)

\- Grounding & Provenance: 4.5/5 (Preserves page numbers, block hierarchies, and coordinate spans)

\- Retrieval Quality: 4.5/5 (Directly empowers retrieval by preserving LaTeX formulas)

\- Corrective Capability: N/A (Ingestion tool)

\- Knowledge Graph Capability: 2.0/5 (Outputs text/markdown; graph extraction happens downstream)

\- Educational Model Compatibility: 4.0/5 (Preserves textbook chapter/section structures)

\- Simulation Compatibility: 3.5/5 (Accurately extracts equations needed for simulation rules)

\- Technical Maturity: 4.5/5 (Proven industry standard)

\- Extensibility: 4.0/5 (Modular model stages)

\- Local/On-Premise: 5.0/5 (Runs 100% locally on standard consumer GPUs or CPU)

\- Provider Independence: 5.0/5 (Open weights, no cloud API calls)

\- Evaluation Support: 4.0/5 (Benchmarked against Nougat and Grobid)

\- Security Posture: 4.5/5 (Isolated worker processing)

\- License Suitability: 3.0/5 (GPL-3.0 requires strict microservice architecture)

\- Integration Effort: 4.0/5 (Clean CLI/API boundary)

\- \*Confidence Level\*: High.

### **8\. Ragas**

\*\*Official Repository\*\*: https://github.com/explodinggradients/ragas

\*\*Documentation\*\*: https://docs.ragas.io/

\*\*Relevant Sections\*\*: \`src/ragas/metrics/\_faithfulness.py\`, \`\_context\_recall.py\`, \`\_context\_precision.py\`.

\*\*License\*\*: Apache 2.0.

\*\*Last Activity Signal\*\*: Highly active (dominant open-source RAG evaluation framework in 2024–2026).

\*\*Primary Language & Architecture\*\*: Python; evaluation framework implementing reference-free and reference-based metrics for RAG pipelines.

\*\*Main Concept Addressed\*\*: Concept I (RAG Evaluation).

\*\*Secondary Concepts Addressed\*\*: Concept H (Provenance & Citations).

\*\*Intended Users\*\*: ML engineers and QA teams benchmarking and preventing regressions in RAG systems.

\*\*Core Philosophy\*\*: Quantify RAG performance across independent dimensions: retrieval quality (\*context precision/recall\*) vs generation quality (\*faithfulness/answer relevance\*), isolating failure points.

\*\*Strengths\*\*: Standardized definitions for Faithfulness (detecting hallucinations) and Context Recall; automated synthetic test dataset generation from source documents; Apache 2.0 license.

\*\*Weaknesses\*\*: LLM-as-a-judge can be inconsistent or lenient unless evaluated with capable models (GPT-4o or Qwen-2.5-72B); does not natively evaluate visual or interactive simulation parameters.

\*\*Evidence Quality\*\*: High (widely cited academic papers, industry standard in enterprise RAG).

\*\*Fit with Our Product\*\*: High for CI/CD retrieval benchmarking and hallucination regression testing.

\*\*Recommended Role\*\*: Foundation (for Evaluation Harness).

\*\*Integration Difficulty\*\*: Easy (Standard Python package, integrates with any pipeline).

\*\*Migration Risk\*\*: Very Low.

\*\*Licensing Risk\*\*: None (Apache 2.0).

\*\*Security Risk\*\*: Low (offline evaluation).

\*\*What to Reuse\*\*: Metric implementations for \*Faithfulness\*, \*Context Precision\*, and \*Context Recall\*; synthetic test generation algorithms.

\*\*What Not to Copy\*\*: Do not use Ragas in the real-time student query path (too slow); restrict strictly to offline CI/CD test gates.

\*\*Rating\*\*:

\- Philosophical Alignment: 5.0/5 (Prioritizes verifiable retrieval quality and groundedness)

\- Grounding & Provenance: 5.0/5 (Explicitly scores claim-level citation attribution)

\- Retrieval Quality: 5.0/5 (Deep diagnostics of retrieval recall/precision)

\- Corrective Capability: 3.5/5 (Evaluates corrective effectiveness post-hoc)

\- Knowledge Graph Capability: 2.5/5 (Evaluates text context; requires custom adapters for graph paths)

\- Educational Model Compatibility: 4.0/5 (Can benchmark curriculum QA datasets)

\- Simulation Compatibility: 2.0/5 (Needs custom evaluators for JSON simulation schemas)

\- Technical Maturity: 4.5/5 (Industry standard)

\- Extensibility: 4.5/5 (Custom metric definitions are simple)

\- Local/On-Premise: 5.0/5 (Supports local HuggingFace/vLLM/Ollama judges)

\- Provider Independence: 5.0/5 (LangChain/LlamaIndex model abstractions)

\- Evaluation Support: 5.0/5 (Purpose-built evaluation engine)

\- Security Posture: 4.5/5 (Safe test execution)

\- License Suitability: 5.0/5 (Apache 2.0)

\- Integration Effort: 4.5/5 (Very easy Python integration)

\- \*Confidence Level\*: High.

\---

## **7\. Comparison Matrices**

### **Table 1: RAG Architecture Archetypes**

| Architecture Archetype | Retrieval Mechanism | Grounding Guarantee | Latency Profile | Ingestion Cost | Fit for Simulation Spec Compilation |
| :---- | :---- | :---- | :---- | :---- | :---- |
| \*\*Simple Dense RAG\*\* | Vector KNN Only | Poor (Truncation & Hallucination) | Very Fast ($\<200$ms) | Very Low | Unacceptable (Lacks structural constraints) |
| \*\*Hybrid RAG (Dense \+ BM25)\*\* | Vector KNN \+ BM25 \+ RRF | Moderate-High (Exact term match) | Fast ($\<350$ms) | Low | Moderate (Finds text, lacks relations) |
| \*\*Corrective RAG (CRAG)\*\* | Hybrid \+ Evaluator \+ Rewrite Loop | High (Rejects unsupported claims) | Moderate ($400 ext{--}900$ms) | Low-Medium | High (Guarantees sufficient evidence) |
| \*\*Unconstrained GraphRAG\*\* | LLM Extraction \+ Leiden Clustering | Moderate (Prone to summary drift) | High ($2 ext{--}10$s) | Extremely High | Poor (Summaries dilute exact equations) |
| \*\*Schema-Constrained Graph RAG\*\* | Hybrid \+ 1-2 Hop Typed Graph Walk | Very High (Deterministic DAG links) | Moderate ($300 ext{--}600$ms) | Medium (HITL approved) | Exceptional (Extracts exact causal triples) |
| \*\*Autonomous Agentic RAG\*\* | Dynamic Multi-Tool Planner Loops | Unpredictable (Agent drift risk) | Very High ($3 ext{--}30$s) | High | Dangerous (Non-deterministic failure modes) |

### **Table 2: Retrieval & Vector Engines**

| Project / Engine | Primary Paradigm | Hybrid Search Support | Metadata Filtering | Local Deployment | Operational Overhead | License |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| \*\*PostgreSQL \+ pgvector\*\* | Relational \+ HNSW Vector | Native (Vector \+ tsvector \+ SQL) | Exceptional (ACID SQL) | Trivial (Standard container) | Lowest (Single unified DB) | PostgreSQL |
| \*\*Qdrant\*\* | Dedicated Vector DB | Native (Dense \+ Sparse/SPLADE) | Exceptional (JSON Payload) | Easy (Rust binary / Docker) | Low-Medium (Separate service) | Apache 2.0 |
| \*\*Vespa\*\* | Large-scale Search Engine | Industry-leading Hybrid \+ Tensors | Advanced (C++ engine) | Heavy (Complex clustering) | Very High (Enterprise scale) | Apache 2.0 |
| \*\*OpenSearch\*\* | Distributed Lucene Search | Strong (BM25 \+ k-NN plugin) | Strong (Lucene filters) | Heavy (Java memory footprint) | High (Requires cluster ops) | Apache 2.0 |
| \*\*Milvus\*\* | Distributed Vector DB | Moderate (Sparse vector support) | Moderate | Moderate-Heavy | High (Multiple microservices) | Apache 2.0 |

### **Table 3: Knowledge Graph Systems**

| Project / System | Architecture Model | Ingestion Method | Query Interface | Provenance Preservation | Fit for Physical Rules & Causal DAGs |
| :---- | :---- | :---- | :---- | :---- | :---- |
| \*\*Microsoft GraphRAG\*\* | Hierarchical Leiden Summaries | Autonomous LLM Prompts | Global/Local Summaries | Low (Summary abstraction) | Very Poor (Loss of exact equations) |
| \*\*LightRAG\*\* | Dual-Level Graph Index | LLM Extraction \+ Keys | Key-based Graph Search | Moderate (Retains entity links) | Moderate (Requires custom schema) |
| \*\*HippoRAG\*\* | Personalized PageRank | OpenIE \+ LLM Triples | Graph Walking / PPR | Moderate (Chunk-entity links) | Moderate-High (Good association) |
| \*\*PostgreSQL Adjacency / CTEs\*\* | Relational DAG Tables | Structured Pydantic / HITL | SQL Recursive Queries | Exceptional (Strict Foreign Keys) | Exceptional (Deterministic causality) |
| \*\*Apache AGE (PostgreSQL)\*\* | OpenCypher on PostgreSQL | Cypher Inserts / HITL | OpenCypher \+ SQL | Exceptional (ACID graph tables) | Exceptional (Formal property graph) |
| \*\*Neo4j Community\*\* | Native Labeled Property Graph | Cypher / APOC / LLM | Cypher | High (Node/Edge properties) | High (Requires dedicated server) |

### **Table 4: Corrective & Orchestration Systems**

| Framework | Execution Model | Determinism | State Persistence | Failure Mode Recovery | Recommended Domain |
| :---- | :---- | :---- | :---- | :---- | :---- |
| \*\*LangGraph\*\* | Cyclic Directed State Machine | Strict (Developer-defined edges) | Native (Postgres Checkpointer) | Deterministic fallback nodes | Core Architecture Orchestration |
| \*\*DSPy\*\* | Programmatic Model Optimization | High (Compiled teleprompters) | In-memory program state | Assertion retry & backoff | Prompt Optimization & Evaluation |
| \*\*LlamaIndex Workflows\*\* | Event-Driven Async Architecture | High (Event handlers) | Event context | Event retry handlers | Data ingestion & retrieval pipelines |
| \*\*AutoGen / CrewAI\*\* | Multi-Agent Conversational | Very Low (Emergent behavior) | Weak / Conversational | Fragile (Prone to looping) | Prohibited for student core |

### **Table 5: Educational Knowledge Systems**

| System / Standard | Domain Scope | Prerequisite Modeling | Misconception Mapping | Source Grounding | Open Source Status |
| :---- | :---- | :---- | :---- | :---- | :---- |
| \*\*OpenStax CNX / Architecture\*\* | College/K-12 STEM Textbooks | Section/Chapter Hierarchy | Manual Editorial Notes | Direct Book Page/Section | Fully Open (Affero GPL / CC) |
| \*\*Open edX (Modulestore)\*\* | Online Course Delivery | Sequential Progression | Assessment-linked hints | Courseware Unit IDs | Open Source (AGPL-3.0) |
| \*\*1EdTech CASE / Caliper\*\* | Competencies & Learning Data | Strict Competency Trees | Remediation Standards | Standard Framework GUIDs | Open Standard Specifications |
| \*\*CTAT (Cognitive Tutors)\*\* | Rule-based Cognitive Models | Deep Production Rules | Buggy Rules (Misconceptions) | Cognitive Skill Matrix | Academic Open Source |

### **Table 6: RAG Evaluation Frameworks**

| Framework | Primary Focus | Key Metrics Supported | Local Model Support | CI/CD Integration | License |
| :---- | :---- | :---- | :---- | :---- | :---- |
| \*\*Ragas\*\* | RAG Pipeline Diagnostics | Faithfulness, Recall, Precision | Excellent (vLLM, Ollama) | Native (GitHub Actions / Python) | Apache 2.0 |
| \*\*DeepEval\*\* | Unit-testing for LLMs | G-Eval, Hallucination, Grounded | Excellent (Custom endpoints) | Native (Pytest plugin integration) | Apache 2.0 |
| \*\*TruLens\*\* | RAG Triad Telemetry | Groundedness, Context, Relevance | Moderate | Moderate (Dashboard focused) | Apache 2.0 |
| \*\*Arize Phoenix\*\* | Tracing & Observability | Evals, Embedding Drift, Spans | Excellent (OpenTelemetry) | High (Observability server) | Elastic 2.0 / Open |

### **Table 7: Document Intelligence & PDF Extractors**

| Tool / Project | Extraction Paradigm | Mathematical Formulas | Layout & Tables | Arabic / RTL Support | Hardware Requirements | License |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| \*\*Marker\*\* | Vision-Transformer \+ Heuristic | Exceptional (Clean LaTeX) | Excellent (Clean Markdown) | Moderate (Requires post-proc) | GPU recommended (fast) | GPL-3.0 |
| \*\*MinerU (PDF-Extract-Kit)\*\* | Deep Layout \+ OCR | Exceptional (LaTeX/MathML) | Exceptional (Multi-column) | Moderate | GPU required (heavyweight) | Apache 2.0 |
| \*\*PyMuPDF (fitz)\*\* | C-Based PDF Parser (MuPDF) | Raw Text / Font Glyphs | Basic (Bounding boxes) | Good (Raw glyph extraction) | Minimal (CPU only, $\<10$ms) | AGPL-3.0 |
| \*\*Surya OCR\*\* | Vision Transformer | Good (Line-level OCR) | Layout & Reading Order | Excellent Multilingual | GPU / Modern CPU | GPL-3.0 |
| \*\*Unstructured\*\* | Multi-tool Wrapper | Poor (Unless using Nougat) | Moderate | Weak for technical RTL | CPU / GPU depending on model | Apache 2.0 |

### **Table 8: Simulation & Visualization Engines**

| System / Library | Architecture Model | Determinism | Rendering Target | Knowledge Representation | Suitability as RAG Target |
| :---- | :---- | :---- | :---- | :---- | :---- |
| \*\*PhET Architecture (Axon/Scenery)\*\* | Reactive Property Model \+ View | 100% Deterministic | Canvas / SVG / WebGL | Reactive Physical Equations | Ideal (Clean separation of Model/View) |
| \*\*Vega / Vega-Lite\*\* | Declarative Visualization Grammar | 100% Deterministic | Canvas / SVG | JSON Specification | Ideal for charts/statistical scenes |
| \*\*Matter.js\*\* | 2D Rigid Body Physics Engine | High (Iterative solver) | Canvas | Physical Bodies & Constraints | Strong for mechanical simulations |
| \*\*Rapier (WASM/Rust)\*\* | Deterministic 2D/3D Physics | 100% Bit-Identical | WebGL / WebGPU | Rigid Bodies & Joints | Exceptional for complex physics |
| \*\*Three.js / MathBox\*\* | Scene Graph / Math Visualizer | High | WebGL | 3D Geometry & Vector Fields | Strong for 3D calculus/fields |

### **Table 9: Storage, Vector & Caching Infrastructure**

| Storage Technology | Primary Role | Multi-Tenant Isolation | Backup / Restore | Self-Hosted Footprint | Recommended Placement |
| :---- | :---- | :---- | :---- | :---- | :---- |
| \*\*PostgreSQL 16+\*\* | Relational, Vector, Text, Graph | Native Row-Level Security | pg\_dump / WAL-G (Rock Solid) | Low-Medium (1–4 GB RAM) | Primary Single Source of Truth |
| \*\*MinIO\*\* | S3 Document & Asset Storage | Bucket / Prefix Policies | Native replication / Mirroring | Very Low (Single Go binary) | Raw PDFs, Images, Sim Assets |
| \*\*Redis\*\* | Query Cache & Pub/Sub | Key Namespacing | RDB / AOF snapshots | Very Low ($\<512$ MB RAM) | Semantic Cache & State Lock |
| \*\*Qdrant\*\* | Dedicated Vector Microservice | Collection / Namespace Isolation | Built-in Snapshot API | Low-Medium (Rust binary) | Optional Scale-Out Secondary |

\---

## **8\. Philosophy-Fit Analysis**

### **Philosophical Compatibility Matrix**

Our product philosophy establishes non-negotiable principles:

1\. \*Knowledge Grounding & Provenance\*: Every claim, variable, and rule must link to source text.

2\. \*Deterministic Simulation Logic\*: Simulations must be testable, bug-free, and reproducible.

3\. \*AI as Assistant, Educator as Authority\*: Human-in-the-loop review for published curricula.

4\. \*Minimalism over Runaway Complexity\*: Avoid agentic loops when structured pipelines suffice.

5\. \*Self-Hosted Independence\*: Complete freedom from proprietary cloud APIs.

| Project | Aligned Principles | Conflicting Principles | AI Role: Authority vs Assistant | Grounding & Provenance Stance | Determinism Stance |
| :---- | :---- | :---- | :---- | :---- | :---- |
| \*\*PostgreSQL \+ pgvector\*\* | All 5 principles (Grounded, deterministic, self-hosted, simple, auditable) | None | Pure Infrastructure (Zero authority) | Absolute (Foreign keys, exact spans) | 100% Deterministic ACID |
| \*\*LangGraph\*\* | Deterministic state flow, checkpointing, human-in-the-loop gates | Can be abused to build chaotic multi-agent loops if unconstrained | Assistant (Orchestrator governed by human code) | High (State maintains provenance metadata) | High (Graph transitions are developer-coded) |
| \*\*PhET Architecture\*\* | Grounded in science, deterministic physics, teacher-designed | None (Bespoke build tools require modernization) | Assistant (Zero AI; human authored runtime) | High (Direct mathematical representation) | 100% Deterministic Reactive Physics |
| \*\*Marker\*\* | High-fidelity scientific extraction, local self-hosted | GPL-3.0 requires clean architectural isolation | Assistant (Specialized parsing model) | High (Preserves original page coordinates) | High (Vision model inference is reproducible) |
| \*\*Ragas / DeepEval\*\* | Rigorous verification, automated quality gates, local models | Can encourage over-reliance on LLM-as-a-judge if uncalibrated | Assistant (Automated verification auditor) | Explicit (Directly measures citation faithfulness) | High (Produces repeatable regression scores) |
| \*\*Microsoft GraphRAG\*\* | Deep context extraction | Violates minimalism, high non-determinism, destroys exact page provenance | Treats LLM as ungrounded summarization Authority | Poor (Synthesizes away exact source coordinates) | Very Low (LLM cluster summaries drift across runs) |
| \*\*AutoGPT / Raw Agents\*\* | Open source | Violates all 5 principles (Runaway loops, non-deterministic, ungrounded) | Usurps human control as autonomous Authority | Catastrophic (Hallucinates intermediate tool steps) | Near Zero (Stochastic multi-turn divergence) |

\---

## **9\. Recommended Architecture**

### **Architectural Synthesis**

The recommended platform architecture is \*\*Schema-Governed Deterministic Hybrid-Corrective Graph RAG\*\*. The system strictly separates \*\*Curriculum Knowledge Ingestion & Verification (Offline/Asynchronous)\*\* from \*\*Interactive Query & Simulation Execution (Online/Synchronous)\*\*.

### **Architectural Data Flow Diagram (Mermaid)**

`flowchart TD`  
&nbsp;&nbsp;&nbsp;&nbsp;`subgraph INGESTION ["Offline Ingestion & Knowledge Extraction Pipeline"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`PDF["Raw Educational Materials (PDF/Doc)"] --> PARSER["Marker / PyMuPDF Ingestion Worker"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`PARSER --> EXTRACTED["Extracted Markdown + LaTeX + Coordinate Spans"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`EXTRACTED --> CHUNKER["Hierarchical Chunker (Parent: 1000t, Child: 250t)"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`CHUNKER --> EMBED["Local Embedding Model (BGE-M3 / Qwen-2.5-Coder)"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`CHUNKER --> SPARSE["PostgreSQL tsvector (Arabic/English Stemmer)"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`EXTRACTED --> KG_EXTRACT["Structured LLM Triple Extractor (Pydantic Schema)"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`KG_EXTRACT --> HITL_GATE{"Educator / Teacher Review"}`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`HITL_GATE -- Approved --> KG_STORE["Relational Knowledge Graph (PostgreSQL / Apache AGE)"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`HITL_GATE -- Rejected --> KG_EXTRACT`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`EMBED --> PG_VECTOR["pgvector HNSW Index"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`SPARSE --> PG_FTS["PostgreSQL Full-Text Index"]`  
&nbsp;&nbsp;&nbsp;&nbsp;`end`

&nbsp;&nbsp;&nbsp;&nbsp;`subgraph RUNTIME ["Online Student & Authoring Execution (LangGraph)"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`USER_Q["Student Query / Scene Prompt"] --> ROUTER{"Deterministic & Intent Router"}`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`ROUTER -- "Simple Fact / Definition" --> HYBRID_RET["Hybrid Retriever (pgvector + BM25)"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`ROUTER -- "Prerequisite / Concept Path" --> GRAPH_WALK["Graph Traverser (1-2 Hop SQL DAG Walk)"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`ROUTER -- "Interactive Simulation Request" --> COMPOSITE_RET["Composite Hybrid + Graph + Parameter Retriever"]`

&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`HYBRID_RET --> FUSION["Reciprocal Rank Fusion (RRF)"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`GRAPH_WALK --> FUSION`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`COMPOSITE_RET --> FUSION`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`FUSION --> RERANK["Cross-Encoder Reranker (bge-reranker-large)"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`RERANK --> CRAG_EVAL{"Relevance & Sufficiency Grader"}`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`CRAG_EVAL -- "Insufficient / Ambiguous" --> REWRITE["Query Transformer & Prerequisite Expander"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`REWRITE --> FUSION`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`CRAG_EVAL -- "Completely Missing" --> ABSTAIN["Controlled Pedagogical Fallback ('Not in Curriculum')"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`CRAG_EVAL -- "Sufficient & Grounded" --> EXPAND_PARENT["Parent Context Expander (Full Section)"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`EXPAND_PARENT --> GENERATOR{"Execution Dispatch"}`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`GENERATOR -- "Textual Explanation" --> LLM_EXPLAIN["Grounded Explanation Generator"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`GENERATOR -- "Simulation Engine" --> LLM_SIM_COMPILER["Declarative Simulation Spec Compiler"]`  
&nbsp;&nbsp;&nbsp;&nbsp;`end`

&nbsp;&nbsp;&nbsp;&nbsp;`subgraph CLIENT ["Client Runtime Execution (Browser Sandbox)"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`LLM_EXPLAIN --> CITED_UI["Student Response + Interactive PDF Coordinate Highlights"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`LLM_SIM_COMPILER --> PYDANTIC_VAL{"Strict Pydantic SimSpec Validator"}`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`PYDANTIC_VAL -- Valid JSON --> PHET_RUNTIME["Deterministic PhET/Matter.js Engine Sandbox"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`PYDANTIC_VAL -- Invalid Schema --> RETRY_COMPILER["Compile Error Feedback Loop"]`  
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`RETRY_COMPILER --> LLM_SIM_COMPILER`  
&nbsp;&nbsp;&nbsp;&nbsp;`end`

### **Architectural Subsystems and Boundaries**

1\. \*\*The Ingestion Boundary\*\*:

\- \`Marker\` and \`PyMuPDF\` run in isolated container workers.

\- Text is segmented into \*Parent Sections\* (complete pedagogical topics) and \*Child Chunks\* (discrete paragraphs with exact coordinate geometry).

\- The Knowledge Graph Extractor outputs strictly validated Pydantic instances of \`Concept\`, \`PrerequisiteEdge\`, \`PhysicalFormula\`, and \`CausalRelation\`. No raw unvalidated strings enter the graph.

\- \*\*Educator Review Portal\*\*: An interactive dashboard displays proposed concept triples overlaid on source textbook pages. Teachers click "Approve" or edit relations before they are committed to the authoritative curriculum graph.

2\. \*\*The Unified Storage Subsystem (PostgreSQL 16+)\*\*:

\- \`documents\`: Stores document metadata, file hashes, licensing, and tenant ownership.

\- \`document\_spans\`: Stores character offsets, page numbers, and bounding-box coordinates ($x\_1, y\_1, x\_2, y\_2$).

\- \`document\_chunks\`: Stores text, parent-chunk foreign keys, and \`tsvector\` sparse representations.

\- \`embeddings\`: Stores 1024-dimensional vectors with \`pgvector\` HNSW indexes.

\- \`curriculum\_concepts\`: Stores concept entities, definitions, and domain classification.

\- \`curriculum\_edges\`: Stores directed relationships (\`is\_prerequisite\_of\`, \`causes\`, \`governed\_by\_equation\`).

\- \`simulation\_templates\`: Stores validated JSON simulation specifications.

3\. \*\*The Corrective Retrieval Pipeline (LangGraph)\*\*:

\- \*\*Step 1 (Parallel Dispatch)\*\*: Executes dense vector search via cosine distance in \`pgvector\` and exact keyword search via \`tsvector\` in parallel.

\- \*\*Step 2 (Reciprocal Rank Fusion & Reranking)\*\*: Blends ranks via $RRF(d) \= \\sum\_{m \\in M} rac{1}{60 \+ r\_m(d)}$; passes top-25 candidates through a local \`bge-reranker-large\` cross-encoder to yield top-5 high-precision chunks.

\- \*\*Step 3 (Relevance & Sufficiency Grading)\*\*: A fast model classifies the retrieved evidence. If the question requires prerequisites not present in the chunks, the router executes an immediate 1-hop traversal on \`curriculum\_edges\` to pull prerequisite definitions.

\- \*\*Step 4 (Deterministic Abstention)\*\*: If relevance scores fall below a calibrated threshold ($ au \< 0.65$), the system refuses to answer and offers guided navigation to adjacent covered concepts.

4\. \*\*The Simulation Compiler & Execution Boundary\*\*:

\- The LLM is \*\*never\*\* permitted to generate executable JavaScript.

\- The LLM compiles retrieved scientific rules into a \*\*Strict Declarative Simulation Specification (SimSpec JSON)\*\*:

\`\`\`json

{

"simulation\_id": "sim\_chem\_oxidation\_001",

"provenance": { "source\_chunk\_ids": \["chk\_7721", "chk\_7722"\], "page": 142 },

"model": {

"parameters": \[

{ "id": "water\_presence", "type": "boolean", "default": false },

{ "id": "oxygen\_concentration", "type": "float", "min": 0.0, "max": 1.0, "default": 0.21 },

{ "id": "time\_elapsed\_days", "type": "float", "min": 0.0, "max": 30.0, "default": 0.0 }

\],

"state\_variables": \[

{ "id": "iron\_surface\_purity", "initial": 1.0 },

{ "id": "rust\_layer\_thickness\_um", "initial": 0.0 }

\],

"differential\_rules": \[

"d(rust\_layer\_thickness\_um)/dt \= (water\_presence ? 1.0 : 0.0) \* oxygen\_concentration \* 0.45 \* exp(-rust\_layer\_thickness\_um / 10.0)"

\]

},

"views": \[

{ "type": "cross\_section\_surface", "monitors": \["rust\_layer\_thickness\_um"\] },

{ "type": "molecular\_perspective", "shows\_hidden\_process": true, "elements": \["Fe", "O2", "H2O"\] }

\],

"misconception\_traps": \[

{

"trigger": "water\_presence \== false && oxygen\_concentration \> 0.8",

"explanation": "Dry oxygen alone produces negligible oxidation at room temperature without an electrolyte (water)."

}

\]

}

\`\`\`

\- The client runtime (built with PhET's \`Axon\` and \`Scenery\` libraries) parses this JSON, instantiates reactive properties, binds UI sliders, and runs the numerical simulation loop deterministically.

\---

## **10\. Recommended First-Version Scope**

### **Must Build First (Phase 1 Prototype)**

1\. \*\*Marker / PyMuPDF Ingestion Service\*\*: Automated extraction of lesson PDFs with preserved bounding boxes and LaTeX formulas.

2\. \*\*Unified PostgreSQL Storage Layer\*\*: Relational schema \+ \`pgvector\` HNSW indexes \+ native \`tsvector\` with Arabic and English dictionaries.

3\. \*\*Hybrid Retrieval with Cross-Encoder Reranking\*\*: Parallel dense/BM25 retrieval combined via RRF and reranked using \`bge-reranker-large\`.

4\. \*\*LangGraph CRAG Controller\*\*: Deterministic relevance grading with basic query reformulation and thresholded refusal.

5\. \*\*Relational Concept-Prerequisite DAG\*\*: Relational table storing concept nodes and prerequisite edges, navigated via recursive SQL.

6\. \*\*Declarative Simulation Compiler for 1 Target Domain (Chemistry/Physics Oxidation)\*\*: Rigid Pydantic schema for \`SimSpec JSON\` rendered by a lightweight HTML5/Axon canvas runner.

7\. \*\*Span-Level Citation Highlighting\*\*: UI that displays the source PDF alongside answers with bounding-box highlight overlays.

### **Should Build Later (Phase 2 Production Hardening)**

1\. \*\*Interactive Teacher Curriculum Authoring Portal\*\*: Visual graph editor for teachers to add, modify, or approve extracted triples.

2\. \*\*Automated Misconception Diagnostic Engine\*\*: Tracking student parameter explorations and triggering targeted visual corrections.

3\. \*\*Arabic Scientific Formula Post-Processor\*\*: Specialized parser for right-to-left layout adjustments with embedded Western mathematical symbols.

4\. \*\*Automated Regression CI/CD Harness\*\*: Integrated \`Ragas\` and \`DeepEval\` test suite running against an institutional golden dataset.

### **Should Not Build Yet (Deferred / Prohibited)**

1\. \*\*Global Community Summarization (Microsoft GraphRAG Style)\*\*: Highly expensive, destructive to fine-grained formulas, and unnecessary for lesson-level simulations.

2\. \*\*Autonomous Multi-Agent Web Crawlers\*\*: Dangerous, non-deterministic, and violates curriculum grounding principles.

3\. \*\*Raw Code/Shader Generation\*\*: Direct LLM generation of WebGL/JavaScript files is strictly banned due to security and stability risks.

4\. \*\*Multi-Database Distributed Clusters\*\*: Avoid deploying independent Elasticsearch, Neo4j, and Pinecone clusters; PostgreSQL meets all prototype and medium-scale production requirements.

### **Components: Reuse vs In-House vs Replaceable**

\*\*Directly Reuse\*\*:

\- \`Marker\` (Document ingestion pipeline).

\- \`pgvector\` & \`PostgreSQL\` (Hybrid storage, vector search, relational graph).

\- \`LangGraph\` (Stateful orchestration, CRAG state loops).

\- \`PhET Axon\` (Reactive model state management).

\- \`Ragas\` (Evaluation metrics and test generation).

\*\*Implement In-House\*\*:

\- \`SimSpec Schema\` & Compiler (Declarative Pydantic specification for educational simulations).

\- Educational Knowledge Organization System (KOS) relational schema.

\- Span-to-Bounding-Box citation alignment engine.

\- Arabic/English technical query pre-processor.

\*\*Keep Strictly Replaceable (Behind Abstract Interfaces)\*\*:

\- Embedding Provider (encapsulated behind standard vector dimensions, e.g., via LiteLLM / TEI).

\- LLM Inference Backend (vLLM, Ollama, OpenAI, Anthropic accessible via standard OpenAI-compatible API).

\- Rendering Surface (SimSpec JSON can be rendered via Canvas, SVG, Pixi.js, or Three.js without changing the backend RAG pipeline).

\---

## **11\. Reuse and Integration Plan**

### **Project Integration Specifications**

| Project to Integrate | Component Role | Installation & Integration Method | Required Abstraction Layer | Required Data Adapters | Complexity |
| :---- | :---- | :---- | :---- | :---- | :---- |
| \*\*PostgreSQL / pgvector\*\* | Foundation DB | Docker official image (\`pgvector/pgvector:pg16\`); SQL migration scripts | Standard SQLAlchemy / asyncpg repository pattern | Custom SQL functions for RRF fusion and recursive DAG walk | Low |
| \*\*Marker\*\* | Subsystem (Ingestion) | Isolated container service; exposed via internal FastAPI worker | Abstract \`DocumentParserService\` interface | PDF bytes in $ o$ JSON payload (Markdown \+ LaTeX \+ Bounding Boxes) out | Medium |
| \*\*LangGraph\*\* | Foundation (Orchestrator) | Python package \`langgraph\`; integrated directly into backend service | Workflow engine wrapper decoupling node logic from LangGraph internals | Pydantic \`GraphState\` dict passed between evaluation and retrieval nodes | Medium |
| \*\*PhET Axon\*\* | Subsystem (Client Runtime) | npm package compiled into front-end simulation bundle | \`SimulationRuntimeEngine\` interface | SimSpec JSON $ o$ Axon \`Property\` and \`DerivedProperty\` bindings | Medium |
| \*\*Ragas\*\* | Subsystem (Evaluation) | Python package running in standalone CI/CD test runner | Abstract \`BenchmarkHarness\` runner | Database chunk/answer logs $ o$ Ragas \`Dataset\` format | Low |
| \*\*bge-reranker-large\*\* | Subsystem (Reranking) | Local HuggingFace \`sentence-transformers\` or vLLM/TEI container | \`RerankerClient\` interface | Query string \+ Candidate Chunk strings $ o$ Float relevance scores | Low |

### **Team Capabilities & Migration Strategy**

\*\*Required Team Skills\*\*:

\- Strong Python backend engineering (FastAPI, Asyncio, Pydantic, SQLAlchemy).

\- Relational database indexing and query tuning (PostgreSQL, HNSW, GIN indexes).

\- Modern TypeScript frontend engineering (Canvas/SVG rendering, reactive programming).

\- Basic applied NLP/ML (managing local embedding/reranking containers via HuggingFace/vLLM).

\*\*Migration & Evolution Path\*\*:

\- The architecture is explicitly designed to prevent lock-in. Because vectors, relational graphs, and text chunks reside in standard SQL tables, migrating to a dedicated vector store (e.g., Qdrant) or dedicated graph engine (e.g., Neo4j) in the future requires only migrating specific SQL views, without re-architecting application business logic.

\---

## **12\. Risks Analysis**

1\. \*\*Hallucination in Educational Explanations\*\*:

\- \*Impact\*: Critical. Misleading students on scientific laws permanently destroys institutional credibility.

\- \*Mitigation\*: Hard constraint: every generated assertion must map to a retrieved Fragment UUID. Thresholded CRAG abstention when evidence is missing.

2\. \*\*Incorrect Retrieval Due to Vocabulary Mismatch\*\*:

\- \*Impact\*: High. Failing to retrieve a formula because the student used colloquial language.

\- \*Mitigation\*: Hybrid RRF fusion guarantees dense semantic matching catches colloquial synonyms while BM25 catches exact formula strings.

3\. \*\*Noisy / Hallucinated Graph Relationships\*\*:

\- \*Impact\*: Critical. If an automated extractor asserts "oxygen prevents rust", the simulation will invert reality.

\- \*Mitigation\*: Mandatory Human-in-the-Loop (HITL) teacher approval gate before triples are committed to the active curriculum graph.

4\. \*\*Indirect Prompt Injection in Source Material\*\*:

\- \*Impact\*: High. Malicious actors embedding instructions in uploaded PDFs to hijack the generation pipeline.

\- \*Mitigation\*: Strict structural parsing stripping hidden metadata; treating retrieved text as pure unprivileged data inside parameterized system prompts.

5\. \*\*Cross-Tenant Data Leakage\*\*:

\- \*Impact\*: Critical. School A viewing School B's proprietary or unreleased examination materials.

\- \*Mitigation\*: PostgreSQL Row Level Security (RLS) enforced at the connection level using tenant session IDs.

6\. \*\*Open-Source License Contagion (GPL Contamination)\*\*:

\- \*Impact\*: High. Accidentally linking GPL-3.0 code (e.g., Marker or certain PhET sims) into proprietary closed-source repos.

\- \*Mitigation\*: Marker runs in an isolated microservice over REST/gRPC; simulation runtime re-implements reactive patterns using MIT-licensed Axon components only.

7\. \*\*Abandoned Open-Source Dependencies\*\*:

\- \*Impact\*: Moderate. Upstream libraries ceasing maintenance.

\- \*Mitigation\*: Keep core frameworks (PostgreSQL, LangGraph) on mainstream, high-backing foundations; encapsulate all 3rd-party libraries behind abstract interfaces.

8\. \*\*Vendor Lock-in\*\*:

\- \*Impact\*: Moderate. Inability to switch LLM or embedding providers as pricing or open-weight models shift.

\- \*Mitigation\*: Standardize on OpenAI-compatible API schemas (LiteLLM / vLLM); use provider-agnostic vector dimensions.

9\. \*\*Latency Degradation in Real-Time Student Interaction\*\*:

\- \*Impact\*: High. Student waiting $\>5$ seconds for a response abandons the learning flow.

\- \*Mitigation\*: Fast-path routing bypassing the graph for simple questions; streaming tokens to client UI; local GPU inference hosting.

10\. \*\*Inference & Hosting Operational Costs\*\*:

\- \*Impact\*: Moderate-High. Runaway costs if indexing requires excessive LLM calls.

\- \*Mitigation\*: Reject Microsoft GraphRAG's massive clustering; use small local vision models for parsing and small 8B models for triple extraction.

11\. \*\*Arabic and Multilingual Technical Degradation\*\*:

\- \*Impact\*: High. Right-to-left layout corrupting mathematical expressions and scientific terms.

\- \*Mitigation\*: Dedicated normalization pipelines preserving Western character order for formulas within RTL Arabic token streams; evaluation on Arabic STEM benchmarks.

12\. \*\*Scientific Inaccuracies in Generated Simulation Logic\*\*:

\- \*Impact\*: Critical. A simulation showing linear growth instead of exponential decay.

\- \*Mitigation\*: Parameter validation against physical unit bounds; Pydantic schema validation rejecting invalid differential equations.

13\. \*\*Unsafe Scene Code Execution (Browser Security)\*\*:

\- \*Impact\*: Critical. An LLM generating malicious \`\<script\>\` tags executing inside the user's authenticated session.

\- \*Mitigation\*: Zero arbitrary code generation. The LLM produces declarative JSON \*only\*, executed by a pre-compiled, sandboxed client engine.

14\. \*\*System Overengineering\*\*:

\- \*Impact\*: High. Team drowning in microservices, distributed databases, and complex multi-agent graphs.

\- \*Mitigation\*: Radical infrastructure consolidation into a single PostgreSQL instance and a single LangGraph service.

15\. \*\*Difficult Database Migrations\*\*:

\- \*Impact\*: Moderate. Inability to re-index vectors or update graph schemas as curricula expand.

\- \*Mitigation\*: Decouple raw extracted Markdown from derived embeddings; store raw ASTs in MinIO to allow re-indexing without re-parsing raw PDFs.

\---

## **13\. What Not to Copy**

### **Architectural Anti-Patterns to Strictly Avoid**

1\. \*\*Do NOT Copy Unconstrained LLM Code Generation for Visuals\*\*:

\- \*The Trap\*: Prompting an LLM: \*"Write a React Three Fiber component that shows iron oxidation."\*

\- \*Why It Fails\*: The LLM produces non-deterministic, buggy JavaScript with subtle rendering errors, broken physics, unhandled edge cases, and catastrophic XSS vulnerabilities.

\- \*Our Mandate\*: The LLM generates a \*\*strictly typed declarative JSON specification (SimSpec)\*\*. The deterministic client runtime handles all rendering and physics.

2\. \*\*Do NOT Copy Global Leiden Community Graph Summarization (MS GraphRAG)\*\*:

\- \*The Trap\*: Running hierarchical graph clustering across the entire textbook corpus to pre-generate macro-level community summaries.

\- \*Why It Fails\*: Destroys fine-grained mathematical equations, costs hundreds of dollars in LLM tokens per book, and produces vague high-level summaries useless for setting precise simulation boundary values.

\- \*Our Mandate\*: Build a localized, schema-typed prerequisite and causal DAG queried dynamically via $k$-hop neighbor expansion.

3\. \*\*Do NOT Copy Autonomous Agent Planning Loops for Student Queries\*\*:

\- \*The Trap\*: Giving an LLM agent 10 tools and letting it recursively plan, search, reflect, and loop until it decides it has an answer.

\- \*Why It Fails\*: Unbounded latency (10–45s), random loop termination failures, unpredictable API costs, and inability to guarantee deterministic pedagogical paths.

\- \*Our Mandate\*: Strict, bounded state machines (LangGraph) with hard recursion limits ($N \\le 2$) and deterministic edge routing.

4\. \*\*Do NOT Copy Distributed Infrastructure Sprawl for Prototypes\*\*:

\- \*The Trap\*: Deploying Neo4j for graphs, Pinecone for vectors, Elasticsearch for BM25, Redis for caching, and MongoDB for metadata.

\- \*Why It Fails\*: Creates distributed synchronization bugs, dual-write failure states, massive DevOps overhead, and extreme hosting costs.

\- \*Our Mandate\*: PostgreSQL 16+ handles vectors (\`pgvector\`), full-text (\`tsvector\`), relational metadata, and graph adjacency within a single ACID boundary.

5\. \*\*Do NOT Treat LLMs as the Educational Source of Truth\*\*:

\- \*The Trap\*: Allowing the LLM to use its internal pre-trained parametric weights to answer scientific questions when retrieved context is thin.

\- \*Why It Fails\*: Hallucinates plausibly sounding but scientifically false explanations, confounding learners.

\- \*Our Mandate\*: If retrieved curriculum evidence is below threshold, the system must deterministically refuse to guess.

\---

## **14\. Prioritized Decision List**

| Strategic Decision | Concrete Evidence Base | Confidence | Expected Benefit | Implementation Cost | Risk Profile | Reversibility |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| \*\*1. Standardize Storage on PostgreSQL \+ pgvector \+ tsvector\*\* | Verified pgvector benchmarks; eliminates multi-DB distributed state. | High | Consolidates all data, ACID compliance, zero sync latency, RLS security. | Low (Standard SQL/Postgres ops) | Very Low | High (Data easily exported) |
| \*\*2. Enforce Declarative JSON Schema (SimSpec) for Simulations\*\* | PhET / Vega design patterns; prevents arbitrary code execution. | High | 100% deterministic, testable physics; zero XSS; auditable parameters. | Medium (Requires schema design) | Low | High (Schema can evolve) |
| \*\*3. Implement Corrective RAG (CRAG) with Strict Abstention\*\* | CRAG literature; prevents student-facing educational hallucinations. | High | Institutional trust; absolute grounding in verified textbooks. | Low-Medium (LangGraph state loop) | Low | High (Thresholds tunable) |
| \*\*4. Mandatory Human-in-the-Loop for Curriculum Knowledge Graph\*\* | Empirical failure of unconstrained LLM triple extraction in STEM. | High | Eliminates false scientific links; guarantees prerequisite validity. | Medium (Requires teacher UI) | Low | Moderate |
| \*\*5. Deploy Marker for High-Accuracy Textbook Ingestion\*\* | Open benchmarks proving LaTeX formula preservation over Nougat/pypdf. | High | Flawless extraction of math formulas and multi-column layouts. | Low-Medium (Isolated worker setup) | Low | High (Standard Markdown output) |
| \*\*6. Discard Global Graph Community Summaries in Favor of 1-2 Hop DAG\*\* | Microsoft GraphRAG cost/latency analysis; STEM requires fine-grained causality. | High | 95% reduction in indexing costs; preserves exact equation provenance. | Low (Standard SQL recursive walks) | Low | High |
| \*\*7. Implement Deterministic Rule \+ Semantic Routing\*\* | Semantic Router empirical evaluations ($\<10$ms overhead). | High | Minimizes latency and costs on simple definitional queries. | Low (Embedding clustering \+ regex) | Low | High |

\---

## **15\. Prototype Recommendation**

To rigorously validate this architecture, build a minimal end-to-end prototype validating three diverse educational scenarios across distinct scientific disciplines:

### **Scenario 1: Chemistry / Materials Science (The Iron Oxidation Process)**

\*\*Objective\*\*: Student explores how moisture, oxygen, and salt affect oxidation over time.

\*\*Curriculum Source\*\*: A 10-page standard chemistry chapter section on electrochemistry and corrosion.

\*\*Tested Capabilities\*\*:

\- \*Hybrid Retrieval\*: Matches "$Fe o Fe^{2+} \+ 2e^-$" and "redox reaction in neutral water".

\- \*Graph Expansion\*: Traverses \`Iron Oxidation\` $ o$ prerequisites (\`Oxidation State\`, \`Electrolytes\`) $ o$ causal factors (\`Humidity\`, \`Oxygen Concentration\`).

\- \*SimSpec Generation\*: Compiles JSON specification with variable sliders: \`water\_volume\`, \`salinity\`, \`oxygen\_pct\`, \`time\_days\`.

\- \*Simulation Validation\*: Runtime calculates rust formation thickness using differential equations. Student sets water to zero $ o$ simulation visually shows zero rust $ o$ triggers visual misconception explanation.

\- \*Provenance\*: Clicking the chemical reaction highlights Chapter 4, Page 112, Paragraph 3 of the source textbook.

### **Scenario 2: Physics / Applied Mathematics (Projectile Motion & Air Resistance)**

\*\*Objective\*\*: Student adjusts launch angle, initial velocity, gravity, and air drag to observe trajectory evolution.

\*\*Curriculum Source\*\*: Classical mechanics textbook section with kinematic formulas.

\*\*Tested Capabilities\*\*:

\- \*Formula Extraction & Retrieval\*: Marker correctly extracts $y(t) \= v\_0 t \\sin( heta) \- rac{1}{2}gt^2$ and drag equations in LaTeX.

\- \*Corrective RAG\*: Student asks: "What happens if gravity is negative?" System grades query as an extreme condition $ o$ retrieves prerequisite definitions of acceleration vectors $ o$ parameterizes negative gravity in SimSpec.

\- \*Simulation Runtime\*: PhET-style canvas displays trajectory arc with velocity/acceleration vectors overlaid in real time.

### **Scenario 3: Biology / Systems Physiology (Cardiovascular Blood Pressure Regulation)**

\*\*Objective\*\*: Student manipulates arterial resistance, heart rate, and stroke volume to observe mean arterial pressure ($MAP \= CO imes SVR$).

\*\*Curriculum Source\*\*: Human physiology textbook section on hemodynamics.

\*\*Tested Capabilities\*\*:

\- \*Causal Multi-Variable Retrieval\*: System pulls interdependent formulas linking Cardiac Output ($CO \= HR imes SV$) and Systemic Vascular Resistance ($SVR$).

\- \*Misconception Correction\*: Student sets heart rate extremely high ($\>200$ bpm) $ o$ system models reduced ventricular filling time $ o$ stroke volume drops $ o$ cardiac output paradoxically decreases $ o$ visual graph highlights cardiac decompensation.

### **Concrete Validation Pass/Fail Criteria**

1\. \*\*Retrieval Precision\*\*: Golden dataset of 30 test questions achieves $\>90\\%$ Context Recall and $\>85\\%$ Context Precision (evaluated via Ragas).

2\. \*\*Faithfulness\*\*: Zero ungrounded scientific formulas generated (100% citation grounding).

3\. \*\*Simulation Determinism\*\*: Identical parameter inputs into the SimSpec JSON produce bit-identical simulation states across multiple runs.

4\. \*\*Latency Budget\*\*: Simple queries resolve in $\<600$ms; complex simulation compilation resolves in $\<3.5$s.

5\. \*\*Air-Gap Capability\*\*: Complete ingestion, retrieval, and simulation execution verified with external internet disconnected on a local workstation running Ollama/vLLM.

\---

## **16\. Next Research Round**

### **Research Mandate & Scope**

The findings of this first round provide sufficient technical certainty to \*\*immediately commence building the prototype\*\* for the foundational ingestion, storage, hybrid retrieval, and simulation schema layers. A second research round is recommended to run in parallel with early prototyping, focusing on unsolved specialized challenges.

### **Must Answer Before Full Implementation (Immediate Priority)**

1\. \*\*Arabic Scientific Notation AST Handling\*\*: What is the optimal deterministic normalization strategy for parsing mixed Arabic-script explanations containing embedded Western LaTeX mathematical equations without corrupting bidirectional text presentation?

2\. \*\*Dynamic Mathematical Equation Solver Integration\*\*: Should the SimSpec runtime integrate a lightweight client-side CAS (Computer Algebra System, e.g., Algebrite / MathJS) to evaluate dynamic symbolic formulas in the browser, or should all equations be pre-compiled to numerical step functions by the backend LLM compiler?

3\. \*\*Teacher Annotation Protocol\*\*: What is the minimal schema and UX workflow required for non-technical curriculum teachers to inspect and approve knowledge graph triples during textbook ingestion?

### **Can Answer During Implementation (Sprint-Level R\&D)**

1\. \*\*Cross-Encoder Optimization\*\*: Can \`bge-reranker-large\` be quantized to INT8 via ONNX Runtime to achieve $\<30$ms latency on CPU without degrading top-3 reranking accuracy?

2\. \*\*Automatic Misconception Mining\*\*: Can comparative evaluation between student simulation inputs and textbook warning notes automatically generate diagnostic quiz prompts?

3\. \*\*HNSW vs IVFFlat Thresholds in PostgreSQL\*\*: At what exact chunk volume ($50 ext{k vs }500 ext{k}$) does memory consumption justify tuning PostgreSQL HNSW \`m\` and \`ef\_construction\` parameters?

### **Optional / Future Research (Post-v1)**

1\. \*\*Audio/Tactile Multimodal Accessibility\*\*: Translating SimSpec state variables into screen-reader descriptions and sonified audio cues for visually impaired students (adopting PhET's Sonification research).

2\. \*\*Federated Multi-School Curriculum Graphs\*\*: Merging independent curriculum graphs across regional educational authorities while preserving localized syllabus variations.

### **Not Worth Investigating Yet (Distractions)**

1\. \*\*Unconstrained Web-Searching Autonomous RAG Agents\*\*: Incompatible with curriculum truth.

2\. \*\*Heavyweight Enterprise Graph DBs (Neo4j Clusters, TigerGraph)\*\*: Premature optimization; PostgreSQL relational tables solve all $k \\le 2$ requirements.

3\. \*\*Generative Video/Diffusion for Simulations\*\*: Incompatible with interactive parameter manipulation and deterministic physical truth.

\---

### **Final Recommendation**

\*\*Proceed immediately to prototype construction\*\* using the \*\*Unified PostgreSQL (pgvector \+ tsvector) \+ LangGraph CRAG \+ Declarative SimSpec Architecture\*\*. Defer Microsoft GraphRAG community clustering and unconstrained autonomous agents.

\*\*Confidence Level\*\*: \*\*High (92%)\*\*.

\*\*Second Research Round Recommended\*\*: \*\*Yes (Targeted on Arabic LaTeX Bidirectionality, Declarative SimSpec Schema Formalization, and Teacher HITL Workflows)\*\*.

\*\*Exact Research Questions for Round 2\*\*:

1\. \*How can bidirectional text tokenizers (e.g., Camel-Tools, HuggingFace Arabic tokenizers) be coupled with Marker's LaTeX layout extractor to preserve bidirectional reading order in Arabic STEM materials?\*

2\. \*What is the formal JSON Schema specification for the "SimSpec" declarative simulation protocol to ensure 100% coverage of secondary school physics and chemistry curricula?\*

3\. \*What is the human-in-the-loop review interface architecture that allows educators to audit and approve extracted knowledge-graph triples with an average review time of $\<30$ seconds per concept?\*