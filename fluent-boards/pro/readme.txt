=== Fluent Boards Pro ===
Contributors: WPManageNinja
Tags: task management, task board, task list, task manager, project management, project manager, project management, to-do list, to-do, kanban board
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.90
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Installation Steps:
-----
1. Goto  Plugins » Add New
2. Then click on "Upload Plugins"
3. Then Click "Choose File" and then select the fluent-boards-pro.zip file
4. Then Click, Install Now after that activate that.
5. You may need to activate the License, and You will get the license at your WPManageNinja.com account.


== Changelog ==
= v1.90 (Date: November 13, 2025) =
- New: Bulk actions in Table View
- New: Bulk restore and delete for archived tasks
- New: Additional webhook triggers
- Improvement: Better cross-board task movement handling
- Improvement: WordPress Guidelines and coding standards compliance
- Improvement: Enhanced error handling and dependency check
- Fixed: Stages ordering issues in board duplication
- Fixed: Board description rendering issue with ampersands
- Fixed: Task deletion issue
- Fixed: Parent ID falsy (zero) value handling
- Fixed: Default preferences not applied to newly added settings
- Fixed: Subtask group assignment inconsistencies
- Fixed: Microsoft office files attachment upload issue
- Other Improvements & Bug Fixes

= v1.86 (Date: September 26, 2025) =
- New: Easy task delete functionality
- Improvement: Task reminder icon added when reminder active.
- Improvement: Webhook data pattern improved , message key added for better handling
- Improvement: Added a close icon to the label popover for easier closing
- Fixed: Custom field select and multiselect error
- Fixed: Kanban view preference error
- Fixed: Task creation issue with default assignee in stage
- Fixed: Duplicate member in assignee
- Other Improvements & Bug Fixes

= v1.85 (Date: September 18, 2025) =
- New: Table View for tasks
- New: Outgoing Webhooks
- New: Task and subtask reminder feature
- New: REST API documentation
- Improvement: 'Assigned' & 'Mentioned' Tab in My tasks section
- Improvement: Added "Create from template" option in task create action in CRM Automation
- Improvement: Improved member search to support partial name matches
- Improvement: Additional translations
- Fixed: Security Issues in board member invitations
- Fixed: Roles Issues in board member management
- Fixed: Frontend portal task creation issue in List view
- Fixed: Incoming webhook task creation issue with watchers and members
- Fixed: Email sent issue when self-assigned to task
- Other Improvements & Bug Fixes

= v1.80 (Date: July 31, 2025) =
- New: Board folderization for better board management.
- New: Task clone with customization for efficient task duplication
- New: Boards list view
- New: Simple notes for subtasks to capture brief task details
- New: Task List view settings for customizable user experience
- New: Rearrange custom fields order with drag functionality for better organization
- New: Added task watcher functionality alongside task assignees
- New: Added ability to make comments public/private in Roadmap tasks.
- New: Mentioned and assigned filters in notification sidebar
- New: Board menu now customizable with ability to add/reorder items
- Improvement: Enhanced JSON export with large data support
- Improvement: Redesigned custom fields interface for better user experience
- Improvement: Added 'load more' in quick search
- Improvement: Open task in new board within move card component for improved task management
- Fixed: Multiple task creation issue in repeat tasks
- Fixed: Resolved board view conflict issue
- Fixed: Stage background color issue in duplicated boards
- Fixed: Multiclick issue during saving
- Fixed: Task positioning issue when moving tasks
- Fixed: Added Fluent Boards to CRM navigation
- Fixed: Task filtering issue with translated priorities
- Fixed: Label popover UX issue in task drawer
- Fixed: Roadmap idea vote count issue
- Fixed: Auto-focus issue in global search
- Other Improvements & Bug Fixes

= v1.65 (Date: June 02, 2025) =
- New: Pinned board functionality for quick access to important boards
- New: Direct task creation from image drops or pastes
- New: Enhanced task filtering by CRM contact association
- New: Task filtering by watchers for better task tracking
- New: Background blur effect in task details dialog for improved focus
- New: Customizable task priority levels via filter hook
- Improvement: Attachments/Files can be mapped with Task from Fluent Form
- Improvement: Added search functionality in move card popover
- Improvement: Customizable task tabs for comments and activities via filter hook
- Improvement: Enhanced comment reply interface
- Improvement: Improved subtask and group creation UX workflow
- Improvement: Direct subtask group creation from CRM contact section
- Fixed: Removed background styling from task title input in list view
- Fixed: Input Method Editor (IME) compatibility in subtask fields
- Fixed: Task date removal functionality issue
- Fixed: Translation Issue
- Other Improvements & Bug Fixes

= v1.60 (Date: May 21, 2025) =
- New: Introduced Subtask Group
- New: Single Board shortcode Functionality
- New: Custom color support for stage background
- Improvement: More Optimized UI in List View, Subtask Section etc.
- Improvement: Scroll to top functionality in task details page
- Improvement: User image now sourced from WordPress user profile
- Improvement: Custom field option added in Board Menu
- Improvement: Task description editor sync and collapse icon added
- Improvement: Background image upload switched from WP Media to local directory
- Improvement: Create label directly from empty search
- Improvement: Added Activity tracking for repeat tasks
- Improvement : Ability to delete board label
- Fixed: Global add-task and stage save button multi-click issue
- Fixed: Multiple emails sent issue on member invitations
- Fixed: Disappearing search popup Issue for keyword "4466" in search
- Fixed: Mention Issue in comments for usernames containing emails or space-separated words
- Fixed: Stage header title text selection issue
- Fixed: CRM contact not showing in task
- Other Improvements

= v1.48 (Date: April 10, 2025) =
- New: Customizable tabs in 'My Tasks' of Dashboard
- New : Time Logs Now offer Date Selection Options
- New: Multi-Select added in Custom Fields
- New: Filter By Task Status
- New: Task descriptions now support "Large Mode" View
- Security: Updated Framework Library
- Improvement: Board Duplication Includes Templates & background
- Improvement: Copy task links directly from the task card
- Improvement: Navbar UI Upated for small screens
- Improvement: Task attachments now support uploading multiple files at once.
- Improvement: Added the ability to delete a time log
- Fixed: Custom Login URL Redirect Issue in Roadmap Authentication Settings
- Fixed: Task loading error in the CRM Contact Section when time tracking is disabled
- Fixed: Issue where multiple clicks created duplicate boards
- Fixed: Stages not appearing issue in global task add button
- Fixed: Improved board and roadmap movement issues
- Fixed: Media attachment functionality issue in task description drawers
- Other Improvements & Bug Fixes

= v1.47 (Date: March 06, 2025) =
- New: Task View Drawer for List View & Calendar View
- New: Global UTF-8 Support for Comments
- Improvement: Navigate to Specific Comment from Notification
- Fixed: Board Menu Translation Issue
- Fixed: Admin Bar Disappearing Issue
- Fixed: Double Logout Icon in Frontend Portal Issue
- Fixed: Task Completion activity bug
- Other Improvements & Bug Fixes

= v1.45 (Date: March 03, 2025) =
- New: CSV Export and Import
- New: Custom color support in Labels
- New: RTL(Right-to-Left) Support
- New: FluentCRM integration in Roadmap 
- New: Added Frontend Portal Link in admin bar for quick access
- New: Easily transition ideas across stages
- Improvement: More translation strings added
- Improvement: Enabled 24-hour time format support
- Improvement: Task link copy via the 'Copy Link' button
- Improvement: Redesigned Features & Modules with improved UI/UX
- Fixed: Multisite Issue
- Fixed: Date diff discrepancy in activities
- Fixed: External storage integration issues
- Fixed: Width overflow issue in task description
- Fixed: Margin issues in frontend portal report
- Fixed: Logged time disappearing when moving tasks between boards
- Fixed: Task created from FluentCRM automations default priority issue
- Other Improvements & Bug Fixes

= v1.40 (Date: January 21, 2025) =
- New : Backblaze storage integration
- New: Digital Ocean storage integration
- New: Create a new stage between existing stages.
- New: Image and Custom color in task Cover
- Improvement:  UX in settings feature module
- Improvement: UI/UX in task comment and image
- Improvement: Replaced pagination with infinite scroll for comments and activities
- Improvement: Stage selection  in Recurring Task.
- Improvement: Updated Roadmap task details with vote counter and improved task type handling
- Improvement: Comment mentions system updated.
- Improvement: Comment mentions notification updated.
- Improvement: Quick Search in mobile view
- Improvement: Automatic deletion of board activities upon board removal
- Improvement: UI improvement in the frontend topbar
- Fixed: Issue where @mentions would occasionally display as "undefined"
- Fixed: Custom field error in the filter
- Fixed: Comment created time issue
- Fixed: Console error while editing webhook.
- Fixed: Notification design-break issue solved.
- Fixed: UI breaking with long activity history text
- Fixed: Improved touch-screen drag icon and task title styling
- Fixed: PHP 7.4 compatibility Issue
- Other Improvements & Bug Fixes

= v1.35 (Date: December 03, 2024) =
- New: Default Stage Assignee.
- New: View-only member Role.
- Improvement: Board-specific layout settings — assign list, Kanban, or calendar view per board.
- Improvement: Board managers can now access timesheets.
- Improvement: Task description editor updated for better usability.
- Improvement: External storage supported for comment and description images.
- Improvement: Calendar week start now follows WordPress settings; updated week, month, and date pickers accordingly.
- Improvement: Better visibility in  All  Activities.
- Improvement: Refreshed media storage design.
- Improvement: Added automatic cleanup of scheduler logs older than 7 days.
- Fixed: Tasks count issue while archive/restore.
- Fixed: Task create input bug in List view
- Fixed: Notification count not clearing after "Mark All as Read."
- Fixed: Task description image issues.
- Other Improvements & Bug Fixes.

= v1.32 (Date: October 23, 2024) =

- Improvement: Task Performance Optimization
- Improvement: More images can be uploaded in comment
- Improvement: Default due time updated
- Improvement: UX in Comment & Reply
- Fixed: Move card issue Resolved and Improved
- Fixed: "Go to Comment" Button in Email Issue
- Fixed: Image issue in task description
- Fixed: Task redirection issue from subtask related notifications
- Fixed: Attachment title issue
- Fixed: Activity issue in profile section
- Fixed: Email send issue for Comment's reply
- Fixed: Comment edit issue for uploaded image
- Other Improvement & Bug Fixes

= v1.30 (Date: September 24, 2024) =

- New: Recurring Task
- New: External Storage Provider: S3, R2 Added
- New: Board Archive Feature
- New: Roadmap Report
- New: Unread Notification Count in task
- New: Easily paste files or URLs directly as attachments
- New: Labels now Searchable
- New: Modifiable Time Track Logs
- New: Enable/Disable Roadmap Options
- Improvement: Task Descriptions Now Support Markdown on Paste
- Improvement: Time Track added in CRM contact section
- Improvement: Custom Fields added in Filter
- Improvement: Datepicker added in Kanban
- Improvement: Archived Tasks searchable as 'archived: (text)' in Quick Search
- Improvement: Number of completed subtasks now visible in the task card
- Improvement: Added Others tab in My Task Section
- Improvement: More Translations
- Fixed: Task assignee Duplicate Issue
- Fixed: UI Breaking Issue in shortcode
- Other Improvement & Bug Fixes

= v1.22 (Date: August 23, 2024) =

- New: Board Export/Import
- New: Global Button to add task/stage/board
- New: Mention in Comment
- New: Image in Comment
- New: Comment/Description Image Paste Feature
- New: Dynamic watching options while creating tasks, commenting, assigning
- New: Smart Search like ID:123
- Improvement: UI/UX - Notification setting moved to User Profile
- Fixed: Roadmap Setting Issue
- Fixed: Codefreeze/Security Plugin Issue
- Fixed: 7G Firewall issue Fixed
- Fixed: Labels in duplicating board
- Fixed: Description Stying, Board activity styling
- Other Improvement & Bug Fixes

= v1.21 (Date: July 29, 2024) =

* New: Fluent Roadmap
* Improvement: Drag & Drop
* Improvement: Profile Section Responsive Now
* Fixed: Due Date Reminder Rescheduling Issue
* Fixed: Email validation issue
* Fixed: Member Invitation Issue for Manager Role
* Fixed: Email Validation Issue in Members
* Other Improvements and Bug Fixes

= v1.20 (Date: July 02, 2024) =

* New: Calendar View
* New: Due Date Reminder (Daily Summary)
* New: Custom Fields
* New: User Profile
* Improvement: Attached labels with Duplicated Boards
* Improvement: Issues In Notification
* Improvement: Comment highlight from a Link/Notification
* Fixed: Subtask Count Issue
* Fixed: Stage Change from More Options
* Fixed: Description Saving Issue on other property changing
* Fixed: User Name/Email not showing in Member Addition
* Fixed: Email Validation Issue in Members
* Other Improvements and Bug Fixes

= v1.13 (Date: June 10, 2024) =

* New: Convert Task to Subtask
* New: WpEditor in Board Description
* New: Added All Board Report & Individual URL
* Improvement: Enhanced Stage Sort UI/UX
* Improvement: Added Due Days in Fluent Form Integration
* Improvement: UX Improvement in Board Member
* Improvement: UX Improvement in Subtask Add/Edit
* Improvement: Date & Time in Comments & Activities. (on hover)
* Fixed: Broken UI for Longtext in description
* Fixed: Deleting imported Subtask Issue
* Fixed: Board Label Scroll Issue
* Fixed: Issue with Member Invitation Email
* Fixed: Task/Subtask Count while Importing from Trello
* Fixed: Issue with Recently Viewed Boards
* Other Improvement & Bug Fixes

= 1.12 (Date: Jun 03, 2024) =
* New: Drag & Drop  in Touch Devices
* New: Completed Task Count now visible on Board Cards
* Improvement: Better UX for task creation
* Improvement: Subtask Edit/Scroll Issue
* Improvement: Allowing Subtasks in Duplicate Board
* Improvement: Improved task moving functionality
* Improvement: Reduced number of AJAX calls
* Fixed: Issue with label editing
* Fixed: Issues with moving tasks and stages
* Fixed: Subtask Modification Issue . Compatible now  with older versions
* Fixed: Recently viewed Boards issue
* Other Improvement & Bug Fixes

= 1.11 (Date: May 28, 2024) =
* New: Timesheet Export
* New: Webhook (Task Creation)
* New: Stage Background
* Added: Custom Solid color & Gradient
* Added: Progress Bar for Subtasks
* Added: All WP Allowed Files Supports in Attachement
* Added: Due Days & Priority for Task Create Action in Automation
* Added: Board Search
* Improvement: Updating description now easier
* Improvement: Role & Permission
* Improvement: Boards UI
* Fixed: Description Formatting Break Issue
* Fixed: Subtask Reorder
* Fixed: Link Add in Attachments
* Fixed: Permission Issue
* Other Improvement & Bug Fixes

= 1.10 (Date: May 20, 2024) =
* Added Frontend Portal via Shortcode
* Improved User Permissions & Roles
* Mobile Responsiveness Improvements
* Notifications Improvements
* Added Translation Files
* Other Improvements & Bug Fixes

= 1.0 =
* Added Basic Reporting (More coming soon)
* Refactored FluentCRM Connections
* Improvement of UI & Bug Fixes

= 0.76 =
* New: Stage Drag and Drop
* New: You can attach zip file now. Files are downloadable.
* Improvement: Board Labels
* Improvement: My Tasks Section
* UI Improvements
* Internal Performance Improvements
* Other Improvements & Bug Fixes

= 0.75 =
* New: Import from Asana
* New: Menu Position in Advanced Modules
* New: Fullscreen Mode
* New: Create Subtask from Task Menu in Kanban
* New: Option to Add/Delete Stages in Onboarding
* New: Completed Tasks in MyTasks
* New: Animation upon Task Status Change
* Improvement: Frontend Settings
* Improvement: Mobile Responsive
* Improvement: UI in Task/Stage Template
* Bug Fix: Broken URL in Task Title Issue
* Bug Fix: Task Count Issue
* Other Improvement and Bug Fixes

= 0.73 =
* New: Advanced Modules with Frontend Capability
* New: Import from Trello
* New: Fluent Support Integration
* New: Task Template & Stage Template
* New: Due Date as well as Start Date with newly improved design
* Improvement: UI of Task Attachment
* Improvement: FluentCRM Contact Section Quick view
* Improvement: Internal Performance
* Bug Fix: 7G Firewall Issue for Nginx
* Other UI/UX improvement & Bug Fixes


= 0.65 =
* Bug Fixes: Notification
* Bug Fixes: My Task
* Bug Fixes: Member Role
* Bug Fixes: Board Member search and CRM contact Search
* Performance Improvement in Table View
* Improvement: UI of Move Task
* New: Duplicate Board
* New: Subtask added in CRM Contact Quick View Section
* New: Fluent Form Integration
* New: Stage Change Trigger in CRM Automation
* Other UI/UX improvement & Bug Fixes


= 0.62 =
* Fix Task Timestamp Issue
* Fix Task move issues
* Improved UI/UX

= 0.60 =
* Initial Release


