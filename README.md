# StudyFlow

**Multi-Role Learning Management System built with Laravel 12 featuring granular RBAC, hierarchical content modeling, polymorphic engagement tracking, and guided multi-step content creation workflows.**

[![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12.0-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![Pest](https://img.shields.io/badge/Tests-Pest_v4-F28D1A?style=flat-square)](https://pestphp.com)
[![License](https://img.shields.io/badge/License-MIT-blue?style=flat-square)](LICENSE)

---

## Executive Summary

StudyFlow is a multi-tenant Learning Management System designed to solve the operational
complexity of managing structured academic content across institutional roles. Unlike
conventional CRUD applications, StudyFlow implements a hierarchical content architecture
where academic material flows through a defined taxonomy -- from Subjects down to individual
Exam Questions -- with each layer enforcing its own access controls and validation rules.

The platform addresses three core institutional needs:

- **Content Governance**: Administrators maintain full control over the content taxonomy,
  user management, and permission delegation while teachers operate within scoped boundaries
  defined at the subject and lecture level.

- **Structured Knowledge Modeling**: Academic content is organized in a strict hierarchy
  (Subject > Lecture > Section > Question / Exam Question) that mirrors real-world course
  design, ensuring content integrity and discoverability.

- **Guided Content Authoring**: A session-based wizard pattern (Study Flow) enables bulk
  content creation across the entire hierarchy, reducing the friction of populating a course
  from scratch.

The system serves three distinct user personas -- administrators who govern the platform,
teachers who create and manage scoped content, and students who consume and engage with
learning materials through a polymorphic favorites system.

StudyFlow differentiates itself from standard tutorial-grade CRUD projects through its
layered authorization model (defense-in-depth across middleware, FormRequest, and controller
layers), its polymorphic engagement system spanning four content types, and its dual study
flow wizard implementation with distinct authorization boundaries for admins and teachers.

---

## Business Model Simulation

StudyFlow is architected as a **B2B SaaS Learning Management Platform** with the following
business characteristics:

| Dimension | Implementation |
|---|---|
| **Licensing Model** | Institutional licensing with role-based seat allocation |
| **Tenant Isolation** | Permission-scoped access per teacher, platform-wide visibility for admins |
| **Content Hierarchy** | Subject > Lecture > Section > Question > Exam Question |
| **Access Control** | Three-tier RBAC (Admin / Teacher / Student) with granular resource permissions |
| **Engagement Layer** | Polymorphic favorites system across four content types |
| **Content Delivery** | PDF attachments, mind maps, dynamic view links, and structured text explanations |
| **API Surface** | Internal JSON endpoints for dynamic UI filtering |
| **Scalability Path** | Permission model supports unlimited teachers with unique subject/lecture scopes |

### Target Users

- **Educational Institutions** seeking centralized content management with delegated
  authoring capabilities and role-appropriate dashboards for administrators, instructors,
  and students.

- **Training Organizations** requiring structured course hierarchies with permission-scoped
  instructors who can only modify content within their assigned domain areas.

- **EdTech Platforms** building multi-tenant knowledge bases with role-differentiated access
  patterns and polymorphic user engagement tracking.

- **Corporate Learning Departments** that need a centralized platform where subject matter
  experts (teachers) create content within their expertise while administrators maintain
  oversight and governance.

---

## System Roles and Permission Architecture

StudyFlow implements a defense-in-depth authorization model with three enforcement layers
operating in sequence: route-level middleware, FormRequest authorization, and controller-level
permission checks. No single layer is relied upon as the sole authorization mechanism.

### Role Definitions

#### Administrator

The Administrator role has unrestricted platform access and is responsible for the governance
layer of the application:

- Full CRUD operations on all platform resources without any permission constraints
- **User Management**: Create, view, edit, and delete user accounts with role assignment
  (admin, teacher, student) through dedicated UserController and UserRequest validation
- **Teacher Permission Delegation**: Assign and revoke subject-level and lecture-level
  permissions for individual teachers through the TeacherPermissionController
- **Admin Study Flow Wizard**: Full access to the session-based multi-step bulk content
  creation workflow across all subjects and lectures
- **Platform Analytics Dashboard**: Aggregate statistics including total user count, subject
  count, lecture count, section count, question count, and exam question count, alongside
  recent activity feeds for users, subjects, and lectures
- **Middleware Bypass**: The TeacherAccessMiddleware immediately passes through any request
  from a user with the admin role, providing zero-friction access to all content routes

#### Teacher

The Teacher role operates within scoped boundaries defined by the `teacher_permissions` table:

- **Scoped Write Access**: Can only create, update, and delete resources (subjects, lectures,
  sections, questions, exam questions) for which explicit TeacherPermission records exist
  linking the teacher to the relevant subject and/or lecture
- **Universal Read Access**: All GET requests for index and show routes are permitted
  regardless of permission records, allowing teachers to view the full content catalog
- **Teacher Study Flow Wizard**: Access to a guided content creation workflow that enforces
  permission checks at every step, including auto-permission assignment on lecture creation
- **Permission-Aware Dashboard**: The teacher dashboard queries the teacher_permissions table
  to display only permitted subjects, lectures, sections, questions, and exam questions
- **Favorites Integration**: Teachers can favorite lectures, questions, and exam questions,
  with favorited items displayed on their dashboard via eager-loaded polymorphic relationships

#### Student

The Student role provides a read-only content consumption experience:

- **Read-Only Access**: Limited to GET requests for index and show routes across all content
  types. Any attempt to access create, edit, update, or delete actions results in HTTP 403
- **Polymorphic Favorites**: Students can toggle love/unlove on lectures, questions, exam
  questions, and sections through the AJAX-powered LoveController
- **Personalized Dashboard**: Displays all subjects with lecture counts, recent lectures,
  and comprehensive lists of all favorited content items
- **No Content Modification**: The TeacherAccessMiddleware explicitly blocks non-GET requests
  from students, ensuring complete data integrity

### Middleware Architecture

#### RoleMiddleware (`role`)

```php
public function handle(Request $request, Closure $next, ...$roles): Response
```

- Accepts variadic role parameters enabling flexible route protection
  (e.g., `role:admin`, `role:admin,teacher`)
- Validates the authenticated user's role against the allowed roles using `in_array()`
- Returns HTTP 403 with a descriptive error message for unauthorized role access
- Redirects unauthenticated users to the login route
- Registered as a named middleware alias `'role'` in `bootstrap/app.php`

#### TeacherAccessMiddleware (`teacher.access`)

```php
public function handle(Request $request, Closure $next): Response
```

- Multi-entity permission enforcement covering five resource types:
  - **Subjects**: Permission checked via `TeacherPermission::where('subject_id', ...)`
  - **Lectures**: Permission checked via `TeacherPermission::where('lecture_id', ...)`
  - **Sections**: Permission resolved through `section->lecture_id` foreign key chain
  - **Questions**: Permission resolved through `question->lecture_id` foreign key chain
  - **Exam Questions**: Permission resolved through `examQuestion->lecture_id` chain
- **Admin bypass**: Immediately passes through for admin role
- **Student restriction**: Allows only GET requests for index and show named routes
- **Teacher enforcement**: Queries teacher_permissions for each resolved route parameter
- Registered as a named middleware alias `'teacher.access'` in `bootstrap/app.php`

### Authorization Flow Diagram

```
Incoming HTTP Request
        |
        v
[Route Middleware Layer]
        |
        |-- auth: Verify authentication
        |-- role:admin or teacher.access: Role/permission check
        |
        v
[FormRequest Layer]
        |
        |-- authorize(): Role-based authorization
        |   BulkLectureRequest: auth()->user()->role === 'admin'
        |   BulkSectionRequest: auth()->user()->role === 'admin'
        |   BulkContentRequest: auth()->user()->role === 'admin'
        |
        |-- rules(): Input validation with custom messages
        |
        v
[Controller Layer]
        |
        |-- TeacherStudyFlowController: Explicit TeacherPermission queries
        |   before every write operation
        |
        v
[Resource Action Executed]
```

This three-layer approach ensures that no single authorization bypass can grant
unauthorized access. Even if middleware is misconfigured, the FormRequest and controller
layers provide independent authorization checks.

---

## Key Features

### Admin Features

- **User Management**: Full CRUD operations on user accounts with role assignment
  (admin, teacher, student) through the UserController, using UserRequest validation
  with conditional password requirements (required on create, optional on update) and
  email uniqueness with `Rule::unique()->ignore()` for update scenarios

- **Teacher Permission Management**: Granular permission delegation at subject and lecture
  levels through the TeacherPermissionController. Administrators can assign a teacher to
  specific subjects and lectures, defining the exact scope of their content authority

- **Admin Study Flow Wizard**: Session-based multi-step bulk content creation workflow
  allowing administrators to populate entire course hierarchies in a guided sequence:
  Select Subject > Bulk Create Lectures > Bulk Create Sections > Bulk Create Content

- **Platform Dashboard**: Aggregate statistics computed via Eloquent count queries across
  all six domain models, with the five most recent users, subjects, and lectures displayed
  as activity feeds using `latest()->take(5)->get()` with eager loaded relationships

- **Complete Content Control**: Unrestricted CRUD access to all subjects, lectures,
  sections, questions, and exam questions through five resource controllers

### Teacher Features

- **Scoped Content Management**: CRUD operations restricted to permitted subjects and
  lectures. Write access is validated at both the middleware layer (TeacherAccessMiddleware)
  and the controller layer (explicit TeacherPermission queries in TeacherStudyFlowController)

- **Teacher Study Flow Wizard**: Guided content creation within permitted subjects with
  a six-step workflow: Start > View Permitted Lectures > Create Lecture > Bulk Sections >
  Bulk Content > Exit. Each step verifies teacher permissions before executing

- **Auto-Permission Assignment**: When a teacher creates a new lecture through the Teacher
  Study Flow, a TeacherPermission record is automatically generated, granting immediate
  write access without administrator intervention:
  ```php
  TeacherPermission::create([
      'teacher_id' => $user->id,
      'subject_id' => $request->subject_id,
      'lecture_id' => $lecture->id,
  ]);
  ```

- **Permission-Aware Dashboard**: Queries teacher_permissions to determine allowed subject
  and lecture IDs, then filters all content types (sections, questions, exam questions)
  through the allowed lecture IDs, ensuring teachers only see relevant data

- **Favorites Dashboard**: Displays favorited lectures, questions, and exam questions
  with eager-loaded subject and lecture relationships for breadcrumb context

### Student Features

- **Content Browsing**: Read-only access to all subjects with `withCount('lectures')`,
  recent lectures, and full CRUD views in read-only mode

- **Polymorphic Favorites**: Toggle love/unlove on four content types (lectures, questions,
  exam questions, sections) through a unified AJAX endpoint returning JSON responses:
  ```json
  {
      "success": true,
      "is_loved": true,
      "message": "Added to favorites!"
  }
  ```

- **Personalized Dashboard**: Displays subjects with lecture counts, the 10 most recent
  lectures, and comprehensive lists of all favorited content organized by type

### Content Management Features

- **Hierarchical Content Model**: Five-level content taxonomy with enforced foreign key
  relationships: Subject > Lecture > Section, with Questions and Exam Questions branching
  from Lectures

- **File Upload Management**: Support for multiple file types with MIME validation:
  - PDFs for lecture materials (max 10MB, `mimes:pdf`)
  - PNG images for mind maps (max 15MB, `mimes:png`)
  - Multiple image formats for question images (max 5MB, `mimes:jpeg,png,jpg,gif`)
  - Multiple solution images per question stored as JSON arrays (max 10 images)

- **Storage Lifecycle Management**: Automatic deletion of old files when replacing uploads
  on update operations. The QuestionController demonstrates complete lifecycle management:
  ```php
  // On update: delete old, store new
  if ($request->hasFile('question_image')) {
      if ($question->question_image) {
          Storage::disk('public')->delete($question->question_image);
      }
      $data['question_image'] = $request->file('question_image')
          ->store('questions/images', 'public');
  }
  ```

- **Rich Section Content**: Each section supports six content fields designed for
  comprehensive learning: quick summary, core concept, Egyptian-style explanation,
  formulas, real-life applications, and dynamic view links

- **Dynamic View Models**: Blueprint JSON storage and HTML content generation system
  linked to sections, with version tracking and generation state flags via the
  `dynamic_view_models` table

### Engagement Features (Polymorphic Favorites)

- **Universal Favorites System**: A single `user_loves` polymorphic pivot table supporting
  four content types through Laravel's morphedByMany/morphToMany relationship pattern

- **Toggle Mechanism**: The User model provides `toggleLove()` and `loves()` methods
  that directly query the `user_loves` table. The LoveController validates the incoming
  type parameter against four allowed values and uses PHP `match` expression to resolve
  the correct model

- **Cross-Entity Support**: Lectures, Questions, Exam Questions, and Sections can all
  be favorited. Each loveable model defines a `lovedByUsers()` relationship for reverse
  lookups

- **Dashboard Integration**: Favorited items appear on teacher and student dashboards
  with eager-loaded relationships:
  ```php
  $lovedLectures     = $user->lovedLectures()->with('subject')->get();
  $lovedQuestions     = $user->lovedQuestions()->with('lecture')->get();
  $lovedExamQuestions = $user->lovedExamQuestions()->with('lecture')->get();
  ```

### API and Dynamic Filtering Features

- **Cascading Dropdown Support**: Two JSON endpoints in APIfilterController for fetching
  lectures by subject and sections by lecture, returning only `['id', 'title']` for
  minimal payload:
  ```
  GET /api/subjects/{subject}/lectures  ->  [{ id, title }, ...]
  GET /api/lectures/{lecture}/sections  ->  [{ id, title }, ...]
  ```

- **Query String Filtering**: Questions and exam questions support filtering by subject
  (via lecture relationship using `whereHas`) and by direct lecture_id. Filters are
  populated with subject and lecture dropdowns in the view layer

- **Pagination with Query Preservation**: `withQueryString()` ensures filter parameters
  (subject_id, lecture_id) are maintained across paginated results, preventing state
  loss during navigation

- **API Resource Transformation**: `LectureResource` provides structured JSON output
  with nested section data (title, core_concept, egyptian_explain, real_life) and
  question data (question_text, idea_text, hint, solution_explanation), providing
  a clean API contract for frontend or external consumption

---

## Technical Architecture

### MVC Separation

StudyFlow follows a strict Model-View-Controller architecture with clear separation
of concerns across every layer:

| Layer | Implementation | Count |
|---|---|---|
| **Models** | Eloquent models with relationships, casts, fillable definitions, and business logic | 8 models |
| **Controllers** | Resource controllers, study flow controllers, API controllers, auth controllers | 15+ controllers |
| **Views** | Blade templates organized by resource type with reusable components | 60+ templates |
| **Form Requests** | Dedicated validation classes with custom error messages and role-based authorization | 12 request classes |
| **Resources** | API resource transformations for JSON output | 1 resource class |
| **Middleware** | Custom authorization middleware for role and permission enforcement | 2 middleware classes |
| **Factories** | Model factories with role-based states for test data generation | 8 factory classes |
| **Seeders** | Database seeders with realistic academic content and idempotent patterns | 9 seeder classes |

### Route Architecture

The routing layer is organized into five distinct middleware-protected groups:

```
Routes (web.php - 93 lines)
  |
  |-- Public Routes
  |   |-- GET /                             (Welcome page)
  |
  |-- Authenticated Routes (auth middleware)
  |   |-- GET /dashboard                    (Role-dispatched dashboard)
  |   |-- GET|PATCH|DELETE /profile         (Profile management)
  |   |-- POST /love/toggle                 (Polymorphic favorites)
  |
  |-- Admin-Only Routes (auth + role:admin middleware)
  |   |-- Route::resource('/users')         (7 RESTful user routes)
  |   |-- Route::resource('/teacher_permissions') (7 RESTful permission routes)
  |   |-- Study Flow prefix group:
  |       |-- GET  /study-flow/start
  |       |-- POST /study-flow/subjects/{subject}/lectures
  |       |-- POST /study-flow/lectures/{lecture}/sections
  |       |-- POST /study-flow/sections/{section}/content
  |       |-- GET  /study-flow/exit
  |
  |-- Teacher-Only Routes (auth + role:teacher middleware)
  |   |-- Teacher Study Flow prefix group:
  |       |-- GET  /teacher-study-flow/start
  |       |-- GET  /teacher-study-flow/lectures
  |       |-- POST /teacher-study-flow/lectures
  |       |-- POST /teacher-study-flow/lectures/{lecture}/sections
  |       |-- POST /teacher-study-flow/sections/{section}/content
  |       |-- GET  /teacher-study-flow/exit
  |
  |-- Shared Content Routes (auth + teacher.access middleware)
  |   |-- Route::resource('/subjects')       (7 RESTful routes)
  |   |-- Route::resource('/lectures')       (7 RESTful routes)
  |   |-- Route::resource('/sections')       (7 RESTful routes)
  |   |-- Route::resource('/questions')      (7 RESTful routes)
  |   |-- Route::resource('/exam_questions') (7 RESTful routes)
  |
  |-- API Routes (auth middleware, api prefix)
      |-- GET /api/subjects/{subject}/lectures
      |-- GET /api/lectures/{lecture}/sections
```

### Form Request Validation Architecture

The system implements 12 dedicated FormRequest classes providing centralized validation
with custom error messages, conditional authorization, and context-aware rules:

| Request Class | Purpose | Authorization | Key Rules |
|---|---|---|---|
| `UserRequest` | User CRUD with conditional password | `true` | `Rule::unique()->ignore()`, conditional `required` via `isMethod('POST')` |
| `LectureRequest` | Lecture validation with file uploads | `true` | `mimes:pdf\|max:10240`, `mimes:png\|max:15360` |
| `QuestionRequest` | Multi-image question validation | `true` | `solution_images.*` nested array, `max:5120` per image, `max:10` total |
| `ExamQuestionRequest` | Dual image exam question validation | `true` | Separate question_image and solution_image validation |
| `SectionRequest` | Section content with URL validation | `true` | 6 nullable text fields, `url\|max:500` for dynamic links |
| `SubjectRequest` | Subject name and description | `true` | `required\|string\|max:255` |
| `TeacherPermissionRequest` | Permission assignment | `true` | Integer validation for three foreign keys |
| `BulkLectureRequest` | Admin bulk lecture creation | `role === 'admin'` | `lectures.*.title` nested validation, `min:1` array |
| `BulkSectionRequest` | Admin bulk section creation | `role === 'admin'` | Seven nested fields per section, URL validation |
| `BulkContentRequest` | Admin bulk content creation | `role === 'admin'` | `required_with` conditional validation |
| `ProfileUpdateRequest` | Breeze profile update | `true` | Email uniqueness with current user exclusion |
| `LoginRequest` | Breeze login with rate limiting | `true` | Email + password with throttle protection |

### Custom Middleware Design

**RoleMiddleware**: Uses PHP's variadic parameter syntax (`...$roles`) to accept any number
of role values. The middleware performs an `in_array()` check against the authenticated
user's `role` attribute, supporting both single-role and multi-role route protection in
a single middleware class.

**TeacherAccessMiddleware**: A 111-line authorization middleware that implements a decision
tree based on the authenticated user's role. For teachers, it resolves up to five different
route model bindings (subject, lecture, section, question, exam_question) and performs
individual TeacherPermission existence queries. For nested entities like sections and
questions, it traces the authorization chain through the `lecture_id` foreign key rather
than requiring separate permission entries for each child resource.

### Polymorphic Relationships

The `user_loves` table implements Laravel's polymorphic many-to-many pattern using the
`morphs()` schema helper:

```
user_loves
  |-- id (auto-increment)
  |-- user_id (FK -> users, CASCADE)
  |-- loveable_type (string: App\Models\Lecture | Question | ExamQuestion | Section)
  |-- loveable_id (unsigned bigint: FK -> respective table)
  |-- created_at, updated_at
```

The User model defines four inverse morphedByMany relationships:
- `lovedLectures()` -> morphedByMany(Lecture::class, 'loveable', 'user_loves')
- `lovedQuestions()` -> morphedByMany(Question::class, 'loveable', 'user_loves')
- `lovedExamQuestions()` -> morphedByMany(ExamQuestion::class, 'loveable', 'user_loves')
- `lovedSections()` -> morphedByMany(Section::class, 'loveable', 'user_loves')

Each loveable model defines a `lovedByUsers()` morphToMany relationship enabling
reverse lookups of all users who favorited a specific content item.

### JSON Column Usage

Two models leverage JSON column casting for flexible data storage:

- **Question model**: The `solution_images` column stores an array of file paths as JSON.
  The model defines `'solution_images' => 'array'` in `$casts`, enabling native PHP array
  operations for storage, retrieval, and iteration during file lifecycle management.

- **DynamicViewModel model**: The `blueprint_json` column stores configuration blueprints
  as JSON with `'blueprint_json' => 'array'` casting, enabling structured data storage
  without additional database tables.

### Eager Loading Strategy

Controllers consistently use eager loading to prevent N+1 query issues across the
application:

| Context | Eager Loading Pattern |
|---|---|
| Lecture index | `Lecture::with('subject')->latest()->paginate(10)` |
| Lecture show | `$lecture->load(['subject', 'sections', 'questions', 'examQuestions'])` |
| Question index | `Question::with('lecture.subject')` |
| Section index | `Section::with('lecture.subject')->latest()->paginate(10)` |
| ExamQuestion index | `ExamQuestion::with('lecture.subject')` |
| Teacher permissions | `TeacherPermission::with(['teacher', 'subject', 'lecture'])` |
| User show | `$user->load('teacherPermissions.subject', 'teacherPermissions.lecture')` |
| Dashboard favorites | `$user->lovedLectures()->with('subject')->get()` |
| Teacher dashboard | `Lecture::whereIn('id', $allowedLectureIds)->with('subject')->get()` |
| Student dashboard | `Subject::withCount('lectures')->get()` |

### Pagination

All index views implement Laravel pagination with configurable page sizes:

| Resource | Items Per Page | Query String Preservation |
|---|---|---|
| Users | 10 | Standard |
| Subjects | 10 | Standard |
| Lectures | 10 | Standard |
| Sections | 10 | Standard |
| Questions | 12 | `withQueryString()` for filters |
| Exam Questions | 12 | `withQueryString()` for filters |
| Teacher Permissions | 10 | Standard |

### Session-Based Wizard State

The Study Flow wizard uses Laravel's session system to maintain wizard state across
multiple HTTP requests:

```php
// Start wizard - set session flag
session(['study_flow' => true]);

// Exit wizard - clear session flag
session()->forget('study_flow');
```

This pattern allows the Blade view layer to conditionally render wizard navigation
elements (next step buttons, progress indicators, exit options) based on whether the
user is currently in a Study Flow session.

### API Resource Transformation

`LectureResource` extends `JsonResource` to transform Lecture models with nested section
and question data into structured JSON responses. The resource maps related sections to
include `title`, `core_concept`, `egyptian_explain`, and `real_life` fields, and maps
related questions to include `question_text`, `idea_text`, `hint`, and
`solution_explanation` fields.

---

## Database Design Highlights

### Schema Overview

The database consists of **10 domain tables** plus Laravel framework tables, with
enforced referential integrity through foreign key constraints:

| Table | Purpose | Columns | Key Features |
|---|---|---|---|
| `users` | User accounts | 7 | `enum role`, `password` hashed |
| `subjects` | Top-level content categories | 4 | Root of content hierarchy |
| `lectures` | Course lectures | 7 | `FK subject_id`, PDF + mindmap paths |
| `sections` | Lecture sub-sections | 10 | `FK lecture_id`, 6 rich content fields |
| `questions` | Practice questions | 10 | `FK lecture_id`, `json solution_images` |
| `exam_questions` | Exam questions | 8 | `FK lecture_id`, dual image support |
| `teacher_permissions` | Access control entries | 5 | Triple FK (teacher, subject, lecture) |
| `user_loves` | Polymorphic favorites | 5 | `morphs('loveable')` |
| `dynamic_view_models` | Dynamic content blueprints | 7 | `json blueprint_json`, version tracking |
| `password_reset_tokens` | Auth tokens | 3 | `PK email` |
| `sessions` | Session storage | 6 | `FK user_id`, IP + user agent |
| `cache` | Cache entries | 3 | Key-value store |
| `jobs` | Queue jobs | 7 | Failed jobs tracking |

### Foreign Key Constraints with Cascade Delete

All domain foreign keys implement `onDelete('cascade')`, ensuring referential integrity
is automatically maintained when parent records are deleted:

```
Subject
  |--[cascade]--> Lectures
  |                 |--[cascade]--> Sections
  |                 |                 |--[cascade]--> DynamicViewModels
  |                 |--[cascade]--> Questions
  |                 |--[cascade]--> ExamQuestions
  |                 |--[cascade]--> TeacherPermissions (via lecture_id)
  |
  |--[cascade]--> TeacherPermissions (via subject_id)

User
  |--[cascade]--> TeacherPermissions (via teacher_id -> users)
  |--[cascade]--> UserLoves (via user_id)
```

### Enum Roles

User roles are enforced at the database level using a MySQL enum column:

```php
$table->enum('role', ['admin', 'teacher', 'student'])->default('student');
```

This provides database-level validation preventing invalid role values and ensures new
user registrations default to the student role.

### Polymorphic Pivot Table

The `user_loves` table uses Laravel's `morphs()` helper:

```php
$table->morphs('loveable');
```

This creates two columns (`loveable_type` as string, `loveable_id` as unsigned big
integer) with a composite index, supporting efficient lookups across four morphable
content types: Lecture, Question, ExamQuestion, and Section.

### Hierarchical Relational Structure

The content hierarchy is enforced through foreign key chains, with each level maintaining
a belongsTo/hasMany relationship pair:

```
Subject (1) -----> (N) Lecture (1) -----> (N) Section (1) -----> (1) DynamicViewModel
                           |
                           |-----> (N) Question
                           |
                           |-----> (N) ExamQuestion
```

### Schema Evolution

The migration history demonstrates iterative schema evolution with two additive
migrations modifying the questions table after initial creation:

1. `2026_01_16_184651_add_question_text_to_questions_table` - Added `question_text`
   column for textual question content
2. `2026_01_16_203315_add_hint_to_questions_table` - Added `hint` column for
   question hints

Both migrations include proper `down()` methods with `dropColumn()` for full
reversibility.

### Idempotent Seeding

The `MohammedSeeder` uses `updateOrCreate()` throughout, allowing the seeder to be
executed multiple times without creating duplicate records:

```php
$admin = User::updateOrCreate(
    ['email' => 'admin@test.com'],      // Match key
    [                                    // Values to set
        'name'     => 'Mohammed Admin',
        'password' => Hash::make('Password'),
        'role'     => 'admin',
    ]
);
```

This is a production-ready seeding pattern that supports incremental data population,
environment resetting, and CI/CD pipeline seeding without manual cleanup.

### Factory States

The `UserFactory` implements three factory states for role-based user generation,
following Laravel's state pattern:

```php
User::factory()->admin()->create();     // Creates admin user
User::factory()->teacher()->create();   // Creates teacher user
User::factory()->student()->create();   // Creates student user
User::factory(10)->student()->create(); // Creates 10 student users
```

Eight model factories are available: UserFactory, SubjectFactory, LectureFactory,
SectionFactory, QuestionFactory, ExamQuestionFactory, TeacherPermissionFactory,
and DynamicViewModelFactory.

---

## Study Flow Wizard Pattern

The Study Flow is a multi-step, session-tracked content creation wizard that guides
users through bulk content population across the hierarchical content model. It is
implemented as a UX-driven backend design decision to reduce the friction of populating
an entire course structure from an empty database.

### Admin Study Flow

The administrator's Study Flow (handled by `StudyFlowController`) provides unrestricted
bulk creation capabilities across the entire content hierarchy:

```
Step 1: Start Wizard
        |
        |  session(['study_flow' => true])
        |  Redirect to subjects.index
        |
        v
Step 2: Select or Create Subject
        |
        |  Standard SubjectController CRUD
        |
        v
Step 3: Bulk Create Lectures
        |
        |  POST /study-flow/subjects/{subject}/lectures
        |  Request: BulkLectureRequest
        |  Validation: lectures.*.title (required), lectures.*.summary (nullable)
        |  Creates N lectures in a single request
        |
        v
Step 4: Bulk Create Sections
        |
        |  POST /study-flow/lectures/{lecture}/sections
        |  Request: BulkSectionRequest
        |  Validation: sections.*.title, sections.*.quick_summary,
        |    sections.*.core_concept, sections.*.egyptian_explain,
        |    sections.*.formulas, sections.*.real_life,
        |    sections.*.dynamic_view_link
        |  Seven validated fields per section item
        |
        v
Step 5: Bulk Create Content
        |
        |  POST /study-flow/sections/{section}/content
        |  Request: BulkContentRequest
        |  Creates questions and exam questions for the section's lecture
        |  Updates section dynamic_view_link
        |  Returns composite success message
        |
        v
Step 6: Exit Wizard
        |
        |  session()->forget('study_flow')
        |  Redirect to dashboard
```

### Teacher Study Flow

The teacher's Study Flow (handled by `TeacherStudyFlowController` at 200 lines)
operates within permission boundaries with explicit authorization at every step:

```
Step 1: Start Wizard
        |
        |  session(['study_flow' => true])
        |  Redirect to teacher-study-flow.lectures
        |
        v
Step 2: View Permitted Lectures
        |
        |  GET /teacher-study-flow/lectures
        |  Queries TeacherPermission for allowed subject and lecture IDs
        |  Displays only permitted content
        |
        v
Step 3: Create Lecture in Permitted Subject
        |
        |  POST /teacher-study-flow/lectures
        |  Permission check: TeacherPermission::where('teacher_id', ...)
        |    ->where('subject_id', ...)->exists()
        |  On success: Auto-creates TeacherPermission for the new lecture
        |
        v
Step 4: Bulk Create Sections
        |
        |  POST /teacher-study-flow/lectures/{lecture}/sections
        |  Permission check: via lecture_id OR subject_id
        |  Uses BulkSectionRequest for validation
        |
        v
Step 5: Bulk Create Content
        |
        |  POST /teacher-study-flow/sections/{section}/content
        |  Permission check: via section->lecture_id chain
        |  Uses BulkContentRequest for validation
        |
        v
Step 6: Exit Wizard
        |
        |  session()->forget('study_flow')
        |  Redirect to dashboard
```

### Nested Validation Pattern

The bulk creation endpoints use Laravel's nested array validation syntax, ensuring
each item in a bulk submission is individually validated:

```php
// BulkLectureRequest
'lectures'           => 'required|array|min:1',
'lectures.*.title'   => 'required|string|max:255',
'lectures.*.summary' => 'nullable|string',

// BulkSectionRequest - seven fields per item
'sections'                     => 'required|array|min:1',
'sections.*.title'             => 'required|string|max:255',
'sections.*.quick_summary'     => 'nullable|string',
'sections.*.core_concept'      => 'nullable|string',
'sections.*.egyptian_explain'  => 'nullable|string',
'sections.*.formulas'          => 'nullable|string',
'sections.*.real_life'         => 'nullable|string',
'sections.*.dynamic_view_link' => 'nullable|url',

// BulkContentRequest - conditional validation
'questions.*.idea'             => 'required_with:questions|string',
'exam_questions.*.idea'        => 'required_with:exam_questions|string',
```

---

## Security Architecture

### Authentication

- **Laravel Breeze** scaffolding providing nine authentication controllers:
  AuthenticatedSessionController, ConfirmablePasswordController,
  EmailVerificationNotificationController, EmailVerificationPromptController,
  NewPasswordController, PasswordController, PasswordResetLinkController,
  RegisteredUserController, and VerifyEmailController

- **Password Hashing**: Automatic hashing via the `hashed` cast on the User model's
  password attribute, ensuring passwords are never stored in plaintext

- **Login Rate Limiting**: The `LoginRequest` implements rate limiting via
  `RateLimiter` with a maximum of 5 attempts, using a throttle key derived from
  the email and IP address combination

- **Session Management**: Database-backed session storage with user agent and IP
  address tracking in the sessions table

### CSRF Protection

All state-changing requests (POST, PUT, PATCH, DELETE) are protected by Laravel's
built-in CSRF token verification middleware. Blade templates include `@csrf` directives
in all forms, and the framework's VerifyCsrfToken middleware is active by default.

### Mass Assignment Protection

All eight domain models define explicit `$fillable` arrays:

| Model | Fillable Attributes |
|---|---|
| User | `name`, `email`, `password`, `role` |
| Subject | `name`, `description` |
| Lecture | `subject_id`, `title`, `pdf_path`, `mindmap_path`, `summary` |
| Section | `lecture_id`, `title`, `quick_summary`, `core_concept`, `egyptian_explain`, `formulas`, `real_life`, `dynamic_view_link` |
| Question | `lecture_id`, `question_text`, `question_image`, `idea_text`, `hint`, `solution_images`, `solution_explanation`, `dynamic_view_link` |
| ExamQuestion | `lecture_id`, `question_image`, `idea`, `solution_image`, `explanation`, `dynamic_view_link` |
| TeacherPermission | `teacher_id`, `subject_id`, `lecture_id` |
| DynamicViewModel | `section_id`, `blueprint_json`, `html_content`, `version`, `is_generated` |

### Role-Based Access Control (Three Layers)

1. **Route-level**: RoleMiddleware with variadic role parameters gates entire route groups
2. **FormRequest-level**: BulkLectureRequest, BulkSectionRequest, and BulkContentRequest
   verify `auth()->user()->role === 'admin'` in the `authorize()` method
3. **Controller-level**: TeacherStudyFlowController performs explicit TeacherPermission
   queries before every write operation

### Scoped Teacher Permissions

The `teacher_permissions` table provides fine-grained access control at both subject
and lecture levels. The TeacherAccessMiddleware resolves permissions for five entity
types with lecture-chain resolution for nested resources, ensuring a teacher who has
permission for a lecture also has implicit permission for its child sections, questions,
and exam questions.

### File Upload Validation

| File Type | MIME Validation | Size Limit | Additional Rules |
|---|---|---|---|
| Lecture PDFs | `file\|mimes:pdf` | 10MB (`max:10240`) | -- |
| Mind Maps | `image\|mimes:png` | 15MB (`max:15360`) | PNG only |
| Question Images | `image\|mimes:jpeg,png,jpg,gif` | 5MB (`max:5120`) | -- |
| Solution Images | `image\|mimes:jpeg,png,jpg,gif` | 5MB each (`max:5120`) | Array, max 10 items |
| Exam Question Image | `image\|mimes:jpeg,png,jpg,gif` | 5MB (`max:5120`) | -- |
| Exam Solution Image | `image\|mimes:jpeg,png,jpg,gif` | 5MB (`max:5120`) | -- |
| Dynamic View Links | `url\|max:500` | -- | URL format validation |

### Storage Lifecycle Management

The application implements complete file lifecycle management across create, update,
and delete operations:

- **Create**: Files are stored in organized subdirectories (`lectures/pdfs/`,
  `lectures/mindmaps/`, `questions/images/`, `questions/solutions/`,
  `exam_questions/images/`, `exam_questions/solutions/`)
- **Update**: Old files are explicitly deleted from the `public` disk before new
  files are stored, preventing orphaned files
- **Delete**: All associated files are cleaned up before the database record is
  removed via `Storage::disk('public')->delete()`

---

## Performance Considerations

### Eager Loading to Prevent N+1 Queries

Every controller that displays related data uses either `Model::with()` on queries
or `$model->load()` on instances. Nested eager loading (e.g., `lecture.subject`,
`teacherPermissions.subject`) is used where hierarchical data needs to be displayed.
The DashboardController specifically uses eager loading across all three role-specific
dashboard methods.

### Pagination

All list views implement pagination with 10-12 items per page. This prevents
unbounded data loading on index pages and ensures consistent response times as
the content library grows. The `latest()` scope ensures newest content appears first.

### Query Filtering

The QuestionController and ExamQuestionController implement server-side filtering
via `whereHas()` for cross-table filters (filtering questions by subject through
the lecture relationship) and `where()` for direct column filters (filtering by
lecture_id). This reduces the query result set before pagination.

### API Endpoint Efficiency

The APIfilterController returns only the required columns:

```php
Lecture::where('subject_id', $subjectId)->orderBy('title')->get(['id', 'title']);
```

This minimizes JSON payload size for cascading dropdown population, transmitting
only the data needed for select option rendering.

### Resource Transformation

LectureResource provides a transformation layer between Eloquent models and JSON
responses, ensuring consistent API contracts and preventing accidental exposure of
internal model attributes or sensitive data.

---

## Engineering Decisions and Architectural Highlights

### Polymorphic Engagement System

Rather than creating separate favorites tables for each content type (four tables with
identical schemas), the system uses a single `user_loves` polymorphic pivot table. This
design reduces schema complexity from four tables to one while supporting favorites across
Lectures, Questions, Exam Questions, and Sections through a unified interface. The User
model exposes four typed relationship methods for querying specific content types and
generic `loves()`/`toggleLove()` methods for the toggle API.

### Granular Permission Middleware Covering Five Entity Types

The TeacherAccessMiddleware is a non-trivial authorization implementation that resolves
route model bindings for subjects, lectures, sections, questions, and exam questions.
For nested entities (sections, questions, exam questions), it performs permission resolution
through the `lecture_id` foreign key chain. This means a teacher with permission for
Lecture #5 automatically has write access to all sections, questions, and exam questions
belonging to that lecture without requiring duplicate permission records.

### Dual Study Flow (Admin vs Teacher)

The system implements two parallel Study Flow wizards with distinct authorization
boundaries: StudyFlowController (125 lines) for administrators with unrestricted access,
and TeacherStudyFlowController (200 lines) for teachers with permission enforcement at
every step. Both controllers share the same BulkSectionRequest and BulkContentRequest
validation classes, demonstrating FormRequest reusability across authorization contexts.

### Auto-Permission Assignment on Lecture Creation

When a teacher creates a new lecture through the Teacher Study Flow, the system
automatically generates a corresponding TeacherPermission record. This design eliminates
the need for administrator intervention after lecture creation and ensures the authoring
teacher has immediate access to add sections and content to their newly created lecture.

### JSON Casting and File Lifecycle Management

The Question model uses Laravel's `array` cast on the `solution_images` JSON column,
enabling native PHP array operations on stored image paths. The QuestionController manages
the complete file lifecycle: upload on create, replace-and-delete on update (including
iterating over the JSON array to delete each individual image), and bulk delete on destroy.

### Defense-in-Depth Authorization

Authorization is enforced at three distinct layers, providing redundant security:

1. **Route middleware**: Blocks unauthorized roles before the request reaches any
   application code
2. **FormRequest authorize()**: Validates role-based access before input validation runs,
   providing a second gate within the request lifecycle
3. **Controller logic**: Performs granular TeacherPermission queries for teacher-scoped
   operations, providing the final authorization check with full context awareness

### Dynamic View Model System

The DynamicViewModel architecture enables JSON blueprint storage and HTML content
generation linked to individual sections. Each dynamic view model tracks its version
number and generation state (`is_generated`), supporting iterative content generation
workflows.

### Role-Differentiated Dashboards

The DashboardController uses PHP 8.0's `match` expression to route authenticated users
to role-specific dashboard views:

```php
return match ($user->role) {
    'admin'   => $this->adminDashboard(),
    'teacher' => $this->teacherDashboard(),
    'student' => $this->studentDashboard(),
    default   => redirect()->route('login'),
};
```

Each dashboard method has its own data requirements and query patterns, ensuring users
see only relevant information with role-appropriate context.

---

## Testing and Development Environment

### Testing Framework

- **Pest v4** configured as the primary testing framework with `pestphp/pest-plugin-laravel`
  for Laravel-specific testing utilities
- Feature tests extend `TestCase` and use `RefreshDatabase` trait for complete database
  isolation between test cases
- PHPUnit configured with in-memory SQLite (`DB_DATABASE=:memory:`) for fast test execution
  without filesystem overhead
- Bcrypt rounds reduced to 4 in testing environment for faster password hashing

### Test Structure

```
tests/
  |-- Pest.php                    (Global configuration with RefreshDatabase for Feature tests)
  |-- TestCase.php                (Base test case extending Laravel's TestCase)
  |-- Feature/
  |   |-- Auth/
  |   |   |-- AuthenticationTest.php
  |   |   |-- EmailVerificationTest.php
  |   |   |-- PasswordConfirmationTest.php
  |   |   |-- PasswordResetTest.php
  |   |   |-- PasswordUpdateTest.php
  |   |   |-- RegistrationTest.php
  |   |-- ExampleTest.php
  |   |-- ProfileTest.php
  |-- Unit/
      |-- ExampleTest.php
```

### Seeder Strategy

- **DatabaseSeeder**: Comprehensive seed data including 1 admin, 3 teachers with names,
  10 factory-generated students, 5 subjects with 3-4 lectures each, 2-3 sections per
  lecture, 2-4 questions per lecture, 2-3 exam questions per lecture, and structured
  teacher permission assignments mapping each teacher to specific subject domains

- **MohammedSeeder**: Idempotent seeder using `updateOrCreate()` throughout with realistic
  academic content (Mathematics, Physics, Chemistry, Biology, Computer Science), complete
  with detailed lecture summaries, section content, question solutions, and exam explanations.
  Includes formatted console output displaying created accounts and data statistics

### Factory States

The UserFactory provides three role-based states:

```php
User::factory()->admin()->create();      // Admin role
User::factory()->teacher()->create();    // Teacher role
User::factory()->student()->create();    // Student role (default)
User::factory(10)->student()->create();  // Batch creation
```

### Authentication Scaffolding

Laravel Breeze provides the complete authentication layer with 9 controllers and 6 views:

- Login with remember me functionality
- Registration with automatic login
- Password reset via email token
- Email verification with notification resend
- Password confirmation for sensitive operations
- Profile management (update name, email, password, delete account)

---

## Installation Guide

### Prerequisites

- PHP 8.2 or higher
- Composer 2.x
- Node.js 18+ and npm
- MySQL 8.0+ or compatible database (MariaDB, PostgreSQL)

### Setup Steps

```bash
# 1. Clone the repository
git clone <repository-url>
cd college_project

# 2. Install PHP dependencies
composer install

# 3. Install JavaScript dependencies
npm install

# 4. Create environment file
cp .env.example .env

# 5. Generate application key
php artisan key:generate

# 6. Configure your database connection in .env
#    DB_CONNECTION=mysql
#    DB_HOST=127.0.0.1
#    DB_PORT=3306
#    DB_DATABASE=studyflow
#    DB_USERNAME=root
#    DB_PASSWORD=

# 7. Run database migrations and seed data
php artisan migrate --seed

# 8. Create storage symlink for file uploads
php artisan storage:link

# 9. Compile frontend assets (development mode)
npm run dev

# 10. Start the development server
php artisan serve
```

### Default Test Accounts

| Role | Email | Password |
|---|---|---|
| Admin | admin@test.com | Password |
| Teacher | teacher@test.com | Password |
| Student | student@test.com | Password |

### Quick Start with Composer Scripts

```bash
# Full setup: install, env, key, migrate, build
composer run setup

# Development: starts server, queue worker, and Vite concurrently
composer run dev

# Testing: clears config cache and runs Pest test suite
composer run test
```

---

## Known Limitations and Future Improvement Opportunities

### Architecture Evolution Opportunities

| Area | Current State | Improvement Path |
|---|---|---|
| **Service Layer** | Business logic resides in controllers | Extract to dedicated service classes for improved testability, reuse, and separation of concerns |
| **Laravel Policies** | Authorization via custom middleware and controller checks | Migrate to Laravel Policy classes for standardized, model-level authorization |
| **Test Coverage** | Breeze authentication tests and example tests | Expand with feature tests covering CRUD operations, Study Flow workflows, permission enforcement, and edge cases |
| **Caching** | No caching layer implemented | Add Redis or Memcached caching for frequently accessed queries (subjects list, lecture counts) |
| **Containerization** | Local development environment only | Add Docker Compose configuration with PHP, MySQL, Redis, and Nginx services |
| **CI/CD Pipeline** | No automated deployment pipeline | Implement GitHub Actions for automated testing, linting, and deployment |
| **API Authentication** | Session-based authentication only | Add Laravel Sanctum for stateless token-based API authentication |
| **Full-Text Search** | Basic query filtering via whereHas | Implement Laravel Scout with Meilisearch or Algolia for comprehensive search |
| **Notifications** | No notification system | Add email and in-app notifications for permission changes, content updates, and study flow completions |
| **Audit Logging** | No activity tracking | Implement model event logging for tracking content modifications and access patterns |
| **Rate Limiting** | Default Laravel rate limiting | Add custom throttling for API endpoints, login attempts, and file uploads |
| **Localization** | Single language | Add multi-language support using Laravel's localization features |

---

## Technology Stack

| Component | Technology | Version |
|---|---|---|
| **Framework** | Laravel | 12.0 |
| **Language** | PHP | 8.2+ |
| **Frontend** | Blade Templates, Tailwind CSS | -- |
| **Build Tool** | Vite | -- |
| **Authentication** | Laravel Breeze | 2.3 |
| **Testing** | Pest | 4.1 |
| **Database** | MySQL (SQLite for testing) | -- |
| **Code Style** | Laravel Pint | 1.24 |
| **Debugging** | Laravel Pail | 1.2.2 |
| **Local Dev** | Laravel Sail | 1.41 |
| **Mocking** | Mockery | 1.6 |
| **Error Handling** | Collision | 8.6 |
| **REPL** | Laravel Tinker | 2.10.1 |

---

## Project Structure

```
app/
  |-- Http/
  |   |-- Controllers/
  |   |   |-- Auth/                (9 authentication controllers)
  |   |   |-- APIfilterController  (JSON endpoints for cascading dropdowns)
  |   |   |-- DashboardController  (Role-dispatched dashboard logic)
  |   |   |-- ExamQuestionController (CRUD with dual image management)
  |   |   |-- LectureController    (CRUD with PDF + mindmap uploads)
  |   |   |-- LoveController       (Polymorphic favorites toggle)
  |   |   |-- QuestionController   (CRUD with multi-image JSON management)
  |   |   |-- SectionController    (CRUD for lecture sections)
  |   |   |-- StudyFlowController  (Admin bulk creation wizard)
  |   |   |-- SubjectController    (CRUD for subjects)
  |   |   |-- TeacherPermissionController (Permission management)
  |   |   |-- TeacherStudyFlowController  (Teacher bulk creation wizard)
  |   |   |-- UserController       (User management with role assignment)
  |   |-- Middleware/
  |   |   |-- RoleMiddleware       (Variadic role-based access control)
  |   |   |-- TeacherAccessMiddleware (Resource-level permission enforcement)
  |   |-- Requests/
  |   |   |-- Auth/LoginRequest    (Rate-limited login validation)
  |   |   |-- BulkContentRequest   (Bulk questions/exam questions validation)
  |   |   |-- BulkLectureRequest   (Bulk lecture validation, admin-only)
  |   |   |-- BulkSectionRequest   (Bulk section validation, admin-only)
  |   |   |-- ExamQuestionRequest  (Exam question + image validation)
  |   |   |-- LectureRequest       (Lecture + file upload validation)
  |   |   |-- QuestionRequest      (Question + multi-image validation)
  |   |   |-- SectionRequest       (Section content validation)
  |   |   |-- SubjectRequest       (Subject validation)
  |   |   |-- TeacherPermissionRequest (Permission assignment validation)
  |   |   |-- UserRequest          (User CRUD with conditional rules)
  |   |-- Resources/
  |       |-- LectureResource      (JSON transformation with nested data)
  |-- Models/
      |-- DynamicViewModel         (JSON blueprint + HTML content)
      |-- ExamQuestion             (Exam question with polymorphic love)
      |-- Lecture                  (Lecture with 4 relationships)
      |-- Question                 (Question with JSON array cast)
      |-- Section                  (Rich content with dynamic view)
      |-- Subject                  (Root content model)
      |-- TeacherPermission        (Granular access control)
      |-- User                     (8 relationships, role enum, love methods)
database/
  |-- factories/                   (8 model factories with role states)
  |-- migrations/                  (13 migration files with cascade FKs)
  |-- seeders/                     (9 seeder classes with idempotent patterns)
resources/
  |-- views/                       (60+ Blade templates)
  |   |-- auth/                    (6 authentication views)
  |   |-- components/              (12 reusable Blade components)
  |   |-- dashboards/              (3 role-specific dashboards)
  |   |-- exam_questions/          (4 CRUD views)
  |   |-- layouts/                 (3 layout templates)
  |   |-- lectures/                (4 CRUD views)
  |   |-- questions/               (4 CRUD views)
  |   |-- sections/                (4 CRUD views)
  |   |-- subjects/                (4 CRUD views)
  |   |-- teacher-study-flow/      (Teacher wizard views)
  |   |-- teacher_permissions/     (Permission management views)
  |   |-- users/                   (User management views)
routes/
  |-- web.php                      (93 lines, 5 route groups)
  |-- auth.php                     (Authentication routes)
tests/
  |-- Feature/                     (Auth suite + profile + example tests)
  |-- Unit/                        (Unit tests)
```

---

## ATS Keywords

```
Laravel 12, PHP 8.2, RBAC, Role-Based Access Control, MVC Architecture,
Eloquent ORM, Blade Templates, Tailwind CSS, Form Request Validation,
Custom Middleware, Polymorphic Relationships, Many-to-Many, MorphToMany,
JSON Columns, Database Migrations, Foreign Key Constraints, Cascade Delete,
Enum Columns, Factory States, Database Seeders, Pest Testing Framework,
PHPUnit, RefreshDatabase, Laravel Breeze, Authentication, Authorization,
CSRF Protection, Mass Assignment Protection, Rate Limiting, File Upload,
MIME Validation, Storage Facade, Eager Loading, N+1 Query Prevention,
Pagination, Query Filtering, WhereHas, API Resources, JSON Response,
Session Management, Wizard Pattern, Bulk Operations, Nested Array Validation,
Route Model Binding, Resource Controllers, Named Routes, Middleware Aliases,
Defense-in-Depth, Multi-Role System, LMS, Learning Management System,
SaaS Architecture, Content Hierarchy, Permission Scoping, Vite, Composer,
npm, MySQL, SQLite, REST API, EdTech Platform, Laravel Sail, Laravel Pint,
Code Quality, Software Architecture, Backend Development, Full-Stack,
Laravel Tinker, Mockery, Collision, FormRequest, Route Groups, Match Expression,
updateOrCreate, Idempotent Seeding, Polymorphic Pivot, JSON Casting, HasFactory
```

---

## License

This project is open-sourced software licensed under the [MIT License](https://opensource.org/licenses/MIT).
