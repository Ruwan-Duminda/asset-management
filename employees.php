<?php
require_once 'db.php';
require_once 'auth_helpers.php';
checkAccess(['admin', 'editor', 'viewer']);

$userRole = $_SESSION['user_role'] ?? 'viewer';
$departments = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$updateMessage = '';
$errorMessage = '';

// Handle Form Submissions (Add / Edit Employee)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($userRole, ['admin', 'editor'])) {
    
    // ACTION: ADD EMPLOYEE
    if (isset($_POST['action']) && $_POST['action'] === 'add_employee') {
        try {
            $emp_id   = trim($_POST['employee_id']);
            $name     = trim($_POST['full_name']);
            $email    = trim($_POST['email']);
            $phone    = trim($_POST['phone']);
            $title    = trim($_POST['job_title']);
            $office   = trim($_POST['office_location']);
            $dept_id  = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
            $status   = $_POST['status'] ?? 'Active';

            $stmt = $pdo->prepare("
                INSERT INTO employees (employee_id, full_name, email, phone, job_title, office_location, department_id, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$emp_id, $name, $email, $phone, $title, $office, $dept_id, $status]);

            if (function_exists('logAudit')) {
                logAudit($pdo, $emp_id, 'CREATE', "Added employee: $name ($status)");
            }

            header('Location: employees.php?added=success');
            exit();
        } catch (PDOException $e) {
            $errorMessage = "Error adding employee: " . $e->getMessage();
        }
    }

    // ACTION: EDIT EMPLOYEE
    if (isset($_POST['action']) && $_POST['action'] === 'edit_employee') {
        try {
            $id       = $_POST['id'];
            $emp_id   = trim($_POST['employee_id']);
            $name     = trim($_POST['full_name']);
            $email    = trim($_POST['email']);
            $phone    = trim($_POST['phone']);
            $title    = trim($_POST['job_title']);
            $office   = trim($_POST['office_location']);
            $dept_id  = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
            $status   = $_POST['status'] ?? 'Active';

            $stmt = $pdo->prepare("
                UPDATE employees 
                SET employee_id = ?, 
                    full_name = ?, 
                    email = ?, 
                    phone = ?, 
                    job_title = ?, 
                    office_location = ?, 
                    department_id = ?,
                    status = ?
                WHERE id = ?
            ");
            $stmt->execute([$emp_id, $name, $email, $phone, $title, $office, $dept_id, $status, $id]);

            if (function_exists('logAudit')) {
                logAudit($pdo, $emp_id, 'UPDATE', "Updated employee details for: $name ($status)");
            }

            $updateMessage = "Employee details updated successfully!";
        } catch (PDOException $e) {
            $errorMessage = "Error updating employee: " . $e->getMessage();
        }
    }
}

// Fetch employees with their assigned assets count & JSON aggregated details
$employees = $pdo->query("
    SELECT e.*, 
           d.name AS dept_name, 
           COUNT(a.id) AS assigned_assets_count,
           COALESCE(
               JSON_ARRAYAGG(
                   IF(a.id IS NOT NULL, JSON_OBJECT(
                       'id', a.id,
                       'asset_tag', a.asset_tag,
                       'brand', a.brand,
                       'model', a.model,
                       'serial_number', a.serial_number,
                       'status', a.status,
                       'condition_status', a.condition_status,
                       'category_name', c.name
                   ), NULL)
               ),
               '[]'
           ) AS assigned_assets
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN assets a ON a.assigned_employee_id = e.id
    LEFT JOIN categories c ON a.category_id = c.id
    GROUP BY e.id
    ORDER BY e.full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ITAM - Employees & Offices</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* CSS rules to format single print document for Employee Equipment Handover */
        @media print {
            body * {
                visibility: hidden;
            }
            #printSection, #printSection * {
                visibility: visible;
            }
            #printSection {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                padding: 20px;
                background: white !important;
                color: black !important;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800">
    <?php renderNav(); ?>
    <main class="max-w-7xl mx-auto px-4 pb-12 space-y-6">
        
        <!-- Alerts -->
        <?php if (!empty($_GET['added']) || !empty($updateMessage)): ?>
            <div class="p-4 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 text-sm font-semibold flex justify-between items-center">
                <span>✅ <?= !empty($updateMessage) ? htmlspecialchars($updateMessage) : "Employee added successfully!" ?></span>
                <button onclick="this.parentElement.remove()" class="text-emerald-600 hover:text-emerald-900">&times;</button>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorMessage)): ?>
            <div class="p-4 rounded-lg bg-rose-50 text-rose-800 border border-rose-200 text-sm font-semibold flex justify-between items-center">
                <span>⚠️ <?= htmlspecialchars($errorMessage) ?></span>
                <button onclick="this.parentElement.remove()" class="text-rose-600 hover:text-rose-900">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Header Controls -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h1 class="text-2xl font-bold">Employee Directory & Offices</h1>
                <p class="text-sm text-slate-500">Manage organizational staff, search active records, and export reports.</p>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="exportTableToCSV('employees_list.csv')" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3.5 py-2 rounded-lg font-medium text-sm shadow transition flex items-center gap-1.5">
                    📥 Export CSV
                </button>
                <?php if (in_array($userRole, ['admin', 'editor'])): ?>
                    <button onclick="document.getElementById('addEmpModal').classList.remove('hidden')" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-medium text-sm shadow transition">
                        + Add Employee
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Search & Filter Bar -->
        <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm grid grid-cols-1 md:grid-cols-4 gap-3 items-center">
            <div class="md:col-span-2">
                <label class="block text-[11px] font-bold text-slate-400 uppercase mb-1">Search Employee</label>
                <input type="text" id="searchInput" onkeyup="filterEmployees()" placeholder="Search by name, EMP ID, email, or title..." class="w-full border p-2 rounded-lg text-sm bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-400 uppercase mb-1">Filter Department</label>
                <select id="deptFilter" onchange="filterEmployees()" class="w-full border p-2 rounded-lg text-sm bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= htmlspecialchars($d['name']) ?>"><?= htmlspecialchars($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex gap-2 items-end">
                <div class="w-full">
                    <label class="block text-[11px] font-bold text-slate-400 uppercase mb-1">Filter Status</label>
                    <select id="statusFilter" onchange="filterEmployees()" class="w-full border p-2 rounded-lg text-sm bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <option value="">All Statuses</option>
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
                <button onclick="resetFilters()" class="border border-slate-300 hover:bg-slate-100 text-slate-600 p-2 rounded-lg text-xs font-semibold h-[38px] transition" title="Reset Filters">
                    🔄
                </button>
            </div>
        </div>

        <!-- Employee Table -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
            <table class="w-full text-left text-sm" id="employeeTable">
                <thead class="bg-slate-50 border-b text-xs text-slate-500 uppercase">
                    <tr>
                        <th class="p-3">Emp ID</th>
                        <th class="p-3">Full Name</th>
                        <th class="p-3">Department</th>
                        <th class="p-3">Job Title / Office</th>
                        <th class="p-3">Email & Phone</th>
                        <th class="p-3 text-center">Status</th>
                        <th class="p-3 text-center">Assigned Devices</th>
                        <?php if (in_array($userRole, ['admin', 'editor'])): ?>
                            <th class="p-3 text-right">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100" id="empTableBody">
                    <?php foreach ($employees as $e): ?>
                        <tr class="emp-row hover:bg-slate-50/50 transition" 
                            data-dept="<?= htmlspecialchars($e['dept_name'] ?? 'Unassigned') ?>" 
                            data-status="<?= htmlspecialchars($e['status'] ?? 'Active') ?>">
                            
                            <!-- Employee Name/ID Clickable Trigger -->
                            <td class="p-3 font-mono font-bold text-indigo-600 cursor-pointer hover:underline cell-id" 
                                onclick='openEmployeePrintDetails(<?= htmlspecialchars(json_encode($e), ENT_QUOTES, "UTF-8") ?>)'>
                                <?= htmlspecialchars($e['employee_id']) ?>
                            </td>
                            <td class="p-3 font-semibold text-slate-900 cursor-pointer hover:text-indigo-600 hover:underline cell-name"
                                onclick='openEmployeePrintDetails(<?= htmlspecialchars(json_encode($e), ENT_QUOTES, "UTF-8") ?>)'>
                                <?= htmlspecialchars($e['full_name']) ?>
                            </td>
                            
                            <td class="p-3 font-medium cell-dept"><?= htmlspecialchars($e['dept_name'] ?? 'Unassigned') ?></td>
                            <td class="p-3 cell-title">
                                <div><?= htmlspecialchars($e['job_title'] ?? 'N/A') ?></div>
                                <div class="text-xs text-slate-400">📍 <?= htmlspecialchars($e['office_location'] ?? 'N/A') ?></div>
                            </td>
                            <td class="p-3 text-xs cell-contact">
                                <div><?= htmlspecialchars($e['email']) ?></div>
                                <div class="text-slate-400"><?= htmlspecialchars($e['phone'] ?? '') ?></div>
                            </td>
                            <td class="p-3 text-center cell-status">
                                <?php if (($e['status'] ?? 'Active') === 'Active'): ?>
                                    <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        ● Active
                                    </span>
                                <?php else: ?>
                                    <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-slate-100 text-slate-500 border border-slate-200">
                                        ○ Inactive
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="p-3 text-center cell-devices">
                                <button onclick='openEmployeePrintDetails(<?= htmlspecialchars(json_encode($e), ENT_QUOTES, "UTF-8") ?>)' 
                                        class="px-2.5 py-1 text-xs font-bold rounded-full bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 transition cursor-pointer"
                                        title="Click to view full profile and print assigned hardware document">
                                    💻 <?= $e['assigned_assets_count'] ?> Device(s)
                                </button>
                            </td>
                            <?php if (in_array($userRole, ['admin', 'editor'])): ?>
                                <td class="p-3 text-right">
                                    <button onclick='openEditEmpModal(<?= htmlspecialchars(json_encode($e), ENT_QUOTES, "UTF-8") ?>)' 
                                            class="text-xs bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-300 px-2.5 py-1 rounded font-medium transition"
                                            title="Edit Employee">
                                        ✏️ Edit
                                    </button>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div id="noResults" class="hidden p-8 text-center text-slate-400 italic text-sm">
                No matching employees found for the given search or filter criteria.
            </div>
        </div>

        <!-- Add Employee Modal -->
        <div id="addEmpModal" class="hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white rounded-xl shadow-xl max-w-lg w-full p-6 space-y-4">
                <div class="flex justify-between items-center border-b pb-3">
                    <h3 class="font-bold text-lg">Add New Employee</h3>
                    <button onclick="document.getElementById('addEmpModal').classList.add('hidden')" class="text-slate-400 hover:text-black font-bold">✕</button>
                </div>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="add_employee">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Employee ID *</label>
                            <input type="text" name="employee_id" placeholder="e.g. EMP-102" required class="w-full border p-2 rounded text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Full Name *</label>
                            <input type="text" name="full_name" required class="w-full border p-2 rounded text-sm">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Email *</label>
                            <input type="email" name="email" required class="w-full border p-2 rounded text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Phone</label>
                            <input type="text" name="phone" class="w-full border p-2 rounded text-sm">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Job Title</label>
                            <input type="text" name="job_title" placeholder="e.g. Officer" class="w-full border p-2 rounded text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Department *</label>
                            <select name="department_id" required class="w-full border p-2 rounded text-sm bg-white">
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Office Location *</label>
                            <input type="text" name="office_location" placeholder="e.g. Room 402" required class="w-full border p-2 rounded text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Status *</label>
                            <select name="status" required class="w-full border p-2 rounded text-sm bg-white">
                                <option value="Active" selected>Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex justify-end space-x-2 pt-2 border-t">
                        <button type="button" onclick="document.getElementById('addEmpModal').classList.add('hidden')" class="px-4 py-2 border rounded text-sm">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white font-bold rounded text-sm shadow hover:bg-indigo-700">Save Employee</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit Employee Modal -->
        <div id="editEmpModal" class="hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white rounded-xl shadow-xl max-w-lg w-full p-6 space-y-4">
                <div class="flex justify-between items-center border-b pb-3">
                    <h3 class="font-bold text-lg">Edit Employee Details</h3>
                    <button onclick="closeEditEmpModal()" class="text-slate-400 hover:text-black font-bold">✕</button>
                </div>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="edit_employee">
                    <input type="hidden" name="id" id="edit_emp_pk_id">

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Employee ID *</label>
                            <input type="text" name="employee_id" id="edit_employee_id" required class="w-full border p-2 rounded text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Full Name *</label>
                            <input type="text" name="full_name" id="edit_full_name" required class="w-full border p-2 rounded text-sm">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Email *</label>
                            <input type="email" name="email" id="edit_email" required class="w-full border p-2 rounded text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Phone</label>
                            <input type="text" name="phone" id="edit_phone" class="w-full border p-2 rounded text-sm">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Job Title</label>
                            <input type="text" name="job_title" id="edit_job_title" class="w-full border p-2 rounded text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Department</label>
                            <select name="department_id" id="edit_department_id" class="w-full border p-2 rounded text-sm bg-white">
                                <option value="">-- None / Unassigned --</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Office Location</label>
                            <input type="text" name="office_location" id="edit_office_location" class="w-full border p-2 rounded text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Status *</label>
                            <select name="status" id="edit_status" required class="w-full border p-2 rounded text-sm bg-white">
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex justify-end space-x-2 pt-2 border-t">
                        <button type="button" onclick="closeEditEmpModal()" class="px-4 py-2 border rounded text-sm">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white font-bold rounded text-sm shadow hover:bg-indigo-700">Update Employee</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Full Employee Profile & Print Document Modal -->
        <div id="printEmpModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-start justify-center p-4 overflow-y-auto">
            <div class="bg-white rounded-xl shadow-2xl max-w-3xl w-full p-6 space-y-6 my-8" id="printSection">
                
                <!-- Print Document Header -->
                <div class="flex justify-between items-start border-b pb-4">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-900">IT Equipment Handover Document</h2>
                        <p class="text-xs text-slate-500">Asset Management System &bull; Issued Date: <?= date('F d, Y') ?></p>
                    </div>
                    <div class="flex gap-2 no-print">
                        <button onclick="window.print()" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold px-3.5 py-2 rounded-lg shadow transition flex items-center gap-1">
                            🖨️ Print Document
                        </button>
                        <button onclick="closePrintEmpModal()" class="text-slate-400 hover:text-black font-bold text-lg px-2">✕</button>
                    </div>
                </div>

                <!-- Employee Details Summary Box -->
                <div class="bg-slate-50 p-4 rounded-xl border border-slate-200">
                    <h3 class="text-xs font-bold uppercase text-slate-400 mb-3 tracking-wider">Employee Information</h3>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
                        <div>
                            <span class="text-xs text-slate-400 block">Full Name:</span>
                            <span id="doc_full_name" class="font-bold text-slate-900"></span>
                        </div>
                        <div>
                            <span class="text-xs text-slate-400 block">Employee ID:</span>
                            <span id="doc_employee_id" class="font-mono font-bold text-indigo-600"></span>
                        </div>
                        <div>
                            <span class="text-xs text-slate-400 block">Department:</span>
                            <span id="doc_dept_name" class="font-semibold text-slate-800"></span>
                        </div>
                        <div>
                            <span class="text-xs text-slate-400 block">Job Title:</span>
                            <span id="doc_job_title" class="text-slate-800"></span>
                        </div>
                        <div>
                            <span class="text-xs text-slate-400 block">Office Location:</span>
                            <span id="doc_office" class="text-slate-800"></span>
                        </div>
                        <div>
                            <span class="text-xs text-slate-400 block">Email & Phone:</span>
                            <span id="doc_contact" class="text-slate-800 text-xs"></span>
                        </div>
                    </div>
                </div>

                <!-- Assigned Devices List -->
                <div>
                    <h3 class="text-xs font-bold uppercase text-slate-400 mb-2 tracking-wider">Assigned Devices & Hardware</h3>
                    <table class="w-full text-left text-sm border border-slate-200 rounded-lg overflow-hidden">
                        <thead class="bg-slate-100 text-xs text-slate-600 uppercase border-b">
                            <tr>
                                <th class="p-2.5">Asset Tag</th>
                                <th class="p-2.5">Category & Model</th>
                                <th class="p-2.5">Serial Number</th>
                                <th class="p-2.5">Condition</th>
                                <th class="p-2.5 text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody id="doc_devices_list" class="divide-y divide-slate-200">
                            <!-- Injected dynamically via JS -->
                        </tbody>
                    </table>
                </div>

                <!-- Legal/Signature Section for Printed Document (Handover & Return) -->
<div class="pt-6 border-t mt-6 space-y-6">
    <p class="text-xs text-slate-500 italic">
        I hereby acknowledge the receipt/return of the IT assets listed above. Handed over assets are in good working condition unless noted otherwise. All assets must be handled in accordance with company IT policies.
    </p>

    <!-- ISSUE / HANDOVER SIGN-OUT SECTION -->
    <div>
        <h4 class="text-xs font-bold uppercase text-slate-600 mb-3 border-b pb-1">1. Issue / Handover Sign-Out</h4>
        <div class="grid grid-cols-2 gap-12">
            <div>
                <div class="border-b border-slate-400 h-8"></div>
                <p class="text-xs font-bold text-slate-700 mt-1">Employee Signature (Received By)</p>
                <p class="text-[11px] text-slate-400">Date: ____ / ____ / ________</p>
            </div>
            <div>
                <div class="border-b border-slate-400 h-8"></div>
                <p class="text-xs font-bold text-slate-700 mt-1">IT Rep Signature (Issued By)</p>
                <p class="text-[11px] text-slate-400">Date: ____ / ____ / ________</p>
            </div>
        </div>
    </div>

    <!-- ASSET RETURN SIGN-IN SECTION -->
    <div>
        <h4 class="text-xs font-bold uppercase text-slate-600 mb-3 border-b pb-1">2. Asset Return Sign-In</h4>
        <div class="grid grid-cols-2 gap-12">
            <div>
                <div class="border-b border-slate-400 h-8"></div>
                <p class="text-xs font-bold text-slate-700 mt-1">Employee Signature (Returned By)</p>
                <p class="text-[11px] text-slate-400">Date: ____ / ____ / ________</p>
            </div>
            <div>
                <div class="border-b border-slate-400 h-8"></div>
                <p class="text-xs font-bold text-slate-700 mt-1">IT Rep Signature (Accepted By)</p>
                <p class="text-[11px] text-slate-400">Date: ____ / ____ / ________</p>
            </div>
        </div>
    </div>
</div>  

                <div class="flex justify-end pt-2 border-t no-print">
                    <button type="button" onclick="closePrintEmpModal()" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold rounded-lg transition">Close</button>
                </div>

            </div>
        </div>

    </main>

    <script>
        // Open Employee Full Profile & Printable Handover Modal
        function openEmployeePrintDetails(emp) {
            let assets = [];
            try {
                const rawAssets = typeof emp.assigned_assets === 'string' 
                    ? JSON.parse(emp.assigned_assets) 
                    : emp.assigned_assets;
                assets = (rawAssets || []).filter(item => item !== null);
            } catch (err) {
                assets = [];
            }

            document.getElementById('doc_full_name').innerText = emp.full_name || 'N/A';
            document.getElementById('doc_employee_id').innerText = emp.employee_id || 'N/A';
            document.getElementById('doc_dept_name').innerText = emp.dept_name || 'Unassigned';
            document.getElementById('doc_job_title').innerText = emp.job_title || 'N/A';
            document.getElementById('doc_office').innerText = emp.office_location || 'N/A';
            document.getElementById('doc_contact').innerText = (emp.email || '') + ' ' + (emp.phone ? '| ' + emp.phone : '');

            const tbody = document.getElementById('doc_devices_list');
            tbody.innerHTML = '';

            if (assets.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" class="p-6 text-center text-slate-400 italic">
                            No hardware devices or assets currently assigned to this employee.
                        </td>
                    </tr>
                `;
            } else {
                assets.forEach(asset => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td class="p-2.5 font-mono font-bold text-indigo-600 text-xs">${escapeHtml(asset.asset_tag || 'N/A')}</td>
                        <td class="p-2.5">
                            <div class="font-semibold text-slate-800 text-xs">${escapeHtml(asset.brand || '')} ${escapeHtml(asset.model || '')}</div>
                            <div class="text-[11px] text-slate-400">${escapeHtml(asset.category_name || 'Uncategorized')}</div>
                        </td>
                        <td class="p-2.5 font-mono text-xs text-slate-600">${escapeHtml(asset.serial_number || 'N/A')}</td>
                        <td class="p-2.5 text-xs text-slate-700">${escapeHtml(asset.condition_status || 'Good')}</td>
                        <td class="p-2.5 text-right font-bold text-xs text-indigo-700">${escapeHtml(asset.status || 'In Use')}</td>
                    `;
                    tbody.appendChild(row);
                });
            }

            document.getElementById('printEmpModal').classList.remove('hidden');
        }

        function closePrintEmpModal() {
            document.getElementById('printEmpModal').classList.add('hidden');
        }

        // Real-Time Table Filter Function
        function filterEmployees() {
            const searchVal = document.getElementById('searchInput').value.toLowerCase().trim();
            const deptVal = document.getElementById('deptFilter').value.toLowerCase();
            const statusVal = document.getElementById('statusFilter').value.toLowerCase();

            const rows = document.querySelectorAll('.emp-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const rowText = row.innerText.toLowerCase();
                const rowDept = (row.getAttribute('data-dept') || '').toLowerCase();
                const rowStatus = (row.getAttribute('data-status') || '').toLowerCase();

                const matchesSearch = rowText.includes(searchVal);
                const matchesDept = !deptVal || rowDept === deptVal;
                const matchesStatus = !statusVal || rowStatus === statusVal;

                if (matchesSearch && matchesDept && matchesStatus) {
                    row.classList.remove('hidden');
                    visibleCount++;
                } else {
                    row.classList.add('hidden');
                }
            });

            const noResults = document.getElementById('noResults');
            if (visibleCount === 0) {
                noResults.classList.remove('hidden');
            } else {
                noResults.classList.add('hidden');
            }
        }

        function resetFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('deptFilter').value = '';
            document.getElementById('statusFilter').value = '';
            filterEmployees();
        }

        // Export Filtered Table View to CSV File
        function exportTableToCSV(filename) {
            const rows = document.querySelectorAll('.emp-row');
            const csv = [];
            
            csv.push(['Emp ID', 'Full Name', 'Department', 'Job Title', 'Office Location', 'Email', 'Phone', 'Status', 'Assigned Devices Count'].join(','));

            rows.forEach(row => {
                if (!row.classList.contains('hidden')) {
                    const id = row.querySelector('.cell-id')?.innerText.trim() || '';
                    const name = row.querySelector('.cell-name')?.innerText.trim() || '';
                    const dept = row.querySelector('.cell-dept')?.innerText.trim() || '';
                    
                    const titleDivs = row.querySelectorAll('.cell-title div');
                    const jobTitle = titleDivs[0]?.innerText.trim() || '';
                    const office = titleDivs[1]?.innerText.replace('📍', '').trim() || '';

                    const contactDivs = row.querySelectorAll('.cell-contact div');
                    const email = contactDivs[0]?.innerText.trim() || '';
                    const phone = contactDivs[1]?.innerText.trim() || '';

                    const status = row.getAttribute('data-status') || '';
                    const devices = row.querySelector('.cell-devices button')?.innerText.replace('💻', '').trim() || '0 Device(s)';

                    const rowData = [
                        `"${id.replace(/"/g, '""')}"`,
                        `"${name.replace(/"/g, '""')}"`,
                        `"${dept.replace(/"/g, '""')}"`,
                        `"${jobTitle.replace(/"/g, '""')}"`,
                        `"${office.replace(/"/g, '""')}"`,
                        `"${email.replace(/"/g, '""')}"`,
                        `"${phone.replace(/"/g, '""')}"`,
                        `"${status.replace(/"/g, '""')}"`,
                        `"${devices.replace(/"/g, '""')}"`
                    ];
                    csv.push(rowData.join(','));
                }
            });

            if (csv.length <= 1) {
                alert('No data available to export.');
                return;
            }

            const csvFile = new Blob([csv.join('\n')], { type: 'text/csv' });
            const downloadLink = document.createElement('a');
            downloadLink.download = filename;
            downloadLink.href = window.URL.createObjectURL(csvFile);
            downloadLink.style.display = 'none';
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);
        }

        // Open & Populate Edit Employee Modal
        function openEditEmpModal(emp) {
            document.getElementById('edit_emp_pk_id').value = emp.id || '';
            document.getElementById('edit_employee_id').value = emp.employee_id || '';
            document.getElementById('edit_full_name').value = emp.full_name || '';
            document.getElementById('edit_email').value = emp.email || '';
            document.getElementById('edit_phone').value = emp.phone || '';
            document.getElementById('edit_job_title').value = emp.job_title || '';
            document.getElementById('edit_department_id').value = emp.department_id || '';
            document.getElementById('edit_office_location').value = emp.office_location || '';
            document.getElementById('edit_status').value = emp.status || 'Active';
            
            document.getElementById('editEmpModal').classList.remove('hidden');
        }

        function closeEditEmpModal() {
            document.getElementById('editEmpModal').classList.add('hidden');
        }

        function escapeHtml(text) {
            if (!text) return '';
            return text.toString()
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        // Close modals on Escape key press
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closePrintEmpModal();
                closeEditEmpModal();
                document.getElementById('addEmpModal').classList.add('hidden');
            }
        });
    </script>
    <?php renderFooter(); ?>
</body>
</html>