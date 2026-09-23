<?php
/**
 * Marking — server-side messages (Arabic / English)
 * --------------------------------------------------------------------------
 * The interface mirrors its language into the `marking_lang` cookie and sends
 * an `X-Lang` header with every API call, so server messages arrive in the
 * same language the user is reading.
 *
 * Usage:  fail(L('err.labNotFound'));
 *         fail(L('err.gradeRange', ['max' => 10]));
 */

declare(strict_types=1);

const LANG_SUPPORTED = ['ar', 'en'];
const LANG_FALLBACK  = 'ar';

/** Resolves the request language once per request. */
function lang(): string
{
    static $resolved = null;
    if ($resolved !== null) return $resolved;

    $candidates = [
        $_SERVER['HTTP_X_LANG'] ?? '',
        $_COOKIE['marking_lang'] ?? '',
        $_GET['lang'] ?? '',
    ];

    foreach ($candidates as $c) {
        $c = strtolower(substr(trim((string)$c), 0, 2));
        if (in_array($c, LANG_SUPPORTED, true)) return $resolved = $c;
    }

    // Fall back to the browser's preference before the default.
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if ($accept !== '' && preg_match('/\b(ar|en)\b/', $accept, $m)) {
        return $resolved = $m[1];
    }

    return $resolved = LANG_FALLBACK;
}

/** Translates a key, interpolating {placeholders}. */
function L(string $key, array $vars = [], ?string $forceLang = null): string
{
    $lang = $forceLang && in_array($forceLang, LANG_SUPPORTED, true) ? $forceLang : lang();
    $entry = MESSAGES[$key] ?? null;
    $text = $entry[$lang] ?? $entry[LANG_FALLBACK] ?? $key;

    foreach ($vars as $k => $v) {
        $text = str_replace('{' . $k . '}', (string)$v, $text);
    }
    return $text;
}

/** "Lab 3" / "المعمل ٣" — used in messages and the audit log. */
function lab_label(int $id, ?string $forceLang = null): string
{
    return L('lab.n', ['n' => $id], $forceLang);
}

const MESSAGES = [

/* ------------------------------------------------------------------ shared */
'lab.n'                => ['ar' => 'المعمل {n}',                 'en' => 'Lab {n}'],

/* ------------------------------------------------------------ infrastructure */
'err.noConfig'         => ['ar' => 'ملف الإعدادات config.php غير موجود — انسخ config.sample.php أولًا.',
                           'en' => 'The configuration file config.php is missing — copy config.sample.php first.'],
'err.server'           => ['ar' => 'حدث خطأ في الخادم. تم تسجيل التفاصيل.',
                           'en' => 'A server error occurred. The details have been logged.'],
'err.db'               => ['ar' => 'تعذّر الاتصال بقاعدة البيانات.',
                           'en' => 'Could not connect to the database.'],
'err.missing'          => ['ar' => 'حقل مطلوب غير موجود: {field}',
                           'en' => 'Required field missing: {field}'],
'err.unknownAction'    => ['ar' => 'إجراء غير معروف.',           'en' => 'Unknown action.'],
'err.postOnly'         => ['ar' => 'هذا الإجراء يقبل طلبات POST فقط.',
                           'en' => 'This action accepts POST requests only.'],
'err.needLogin'        => ['ar' => 'يلزم تسجيل الدخول.',          'en' => 'Sign-in required.'],
'err.mainOnly'         => ['ar' => 'هذه الصلاحية للمشرف العام فقط.',
                           'en' => 'This action is restricted to the Main TA.'],
'err.sessionExpired'   => ['ar' => 'انتهت الجلسة — يُرجى تسجيل الدخول مرة أخرى.',
                           'en' => 'Your session has expired — please sign in again.'],
'err.saveFailed'       => ['ar' => 'تعذّر إتمام الحفظ.',          'en' => 'The save could not be completed.'],

/* -------------------------------------------------------------------- auth */
'err.credsRequired'    => ['ar' => 'أدخل اسم المستخدم وكلمة المرور.',
                           'en' => 'Enter your username and password.'],
'err.badCreds'         => ['ar' => 'اسم المستخدم أو كلمة المرور غير صحيحة.',
                           'en' => 'The username or password is incorrect.'],
'err.tooManyTries'     => ['ar' => 'عدد محاولات كبير — يُرجى الانتظار {mins} دقيقة ثم المحاولة مرة أخرى.',
                           'en' => 'Too many attempts — please wait {mins} minutes and try again.'],

/* ------------------------------------------------------------------- marks */
'err.badStatus'        => ['ar' => 'حالة حضور غير معروفة.',       'en' => 'Unknown attendance status.'],
'err.labNotFound'      => ['ar' => 'المعمل غير موجود.',           'en' => 'The lab was not found.'],
'err.studentNotFound'  => ['ar' => 'الطالب غير موجود.',           'en' => 'The student was not found.'],
'err.gradeRange'       => ['ar' => 'يجب أن تكون الدرجة بين ٠ و{max}.',
                           'en' => 'The grade must be between 0 and {max}.'],
'err.closedNeedReason' => ['ar' => 'المعمل مغلق — اكتب سببًا واضحًا للتعديل (٥ أحرف على الأقل).',
                           'en' => 'The lab is closed — provide a clear reason for the amendment (at least 5 characters).'],
'err.needsApproval'    => ['ar' => 'هذا الطالب حاصل على درجة من معيد آخر — يلزم موافقة المشرف العام.',
                           'en' => 'This student already has a grade from another TA — the Main TA must approve the change.'],
'err.amendOtherReason' => ['ar' => 'أنت تعدّل درجة معيد آخر — اكتب السبب، وسيُسجَّل في السجل.',
                           'en' => 'You are amending another TA’s grade — provide a reason; it will be recorded in the log.'],
'err.pendingExists'    => ['ar' => 'يوجد طلب تعديل قيد المراجعة لهذا الطالب — في انتظار قرار المشرف العام.',
                           'en' => 'A change request for this student is pending the Main TA’s decision.'],
'mark.offlineReason'   => ['ar' => 'إدخال دون اتصال — سُجِّل والمعمل مفتوح ووصل بعد الإغلاق.',
                           'en' => 'Offline entry — recorded while the lab was open and received after it closed.'],

/* ---------------------------------------------------------------- requests */
'err.reqReason'        => ['ar' => 'اكتب سببًا واضحًا للطلب — سيقرؤه المشرف العام.',
                           'en' => 'Provide a clear reason for the request — the Main TA will read it.'],
'err.reqExists'        => ['ar' => 'يوجد بالفعل طلب قيد المراجعة لهذا الطالب.',
                           'en' => 'A request for this student is already pending.'],
'err.badDecision'      => ['ar' => 'قرار غير معروف.',             'en' => 'Unknown decision.'],
'err.rejectNote'       => ['ar' => 'اكتب سبب الرفض ليتمكن المعيد من فهم القرار.',
                           'en' => 'Provide a reason for the rejection so the TA can understand the decision.'],
'err.reqNotFound'      => ['ar' => 'الطلب غير موجود.',            'en' => 'The request was not found.'],
'err.reqDecided'       => ['ar' => 'تم البتّ في هذا الطلب بالفعل.',
                           'en' => 'This request has already been decided.'],
'err.decideFailed'     => ['ar' => 'تعذّر تنفيذ القرار.',          'en' => 'The decision could not be applied.'],

/* -------------------------------------------------------------------- labs */
'err.badState'         => ['ar' => 'حالة غير معروفة.',            'en' => 'Unknown state.'],
'err.badDate'          => ['ar' => 'تاريخ غير صحيح.',             'en' => 'Invalid date.'],
'err.rubricMin'        => ['ar' => 'يجب إدخال بند واحد على الأقل.',
                           'en' => 'At least one criterion is required.'],
'err.rubricMax'        => ['ar' => 'الحد الأقصى ٢٠ بندًا.',        'en' => 'A maximum of 20 criteria is allowed.'],
'err.rubricNoText'     => ['ar' => 'البند رقم {n} بدون نص.',       'en' => 'Criterion {n} has no text.'],
'err.rubricTextLong'   => ['ar' => 'نص البند طويل جدًا (٢٥٥ حرفًا كحد أقصى).',
                           'en' => 'The criterion text is too long (255 characters maximum).'],
'err.rubricPoints'     => ['ar' => 'يجب أن تكون نقاط البند بين ٠ و١٠٠.',
                           'en' => 'Criterion points must be between 0 and 100.'],
'err.rubricTotal'      => ['ar' => 'يجب أن يكون مجموع النقاط أكبر من صفر.',
                           'en' => 'The total points must be greater than zero.'],

/* ------------------------------------------------------------------- admin */
'err.pickOtherTa'      => ['ar' => 'اختر معيدًا مختلفًا.',         'en' => 'Choose a different TA.'],
'err.taNotFound'       => ['ar' => 'المعيد غير موجود.',           'en' => 'The teaching assistant was not found.'],
'err.noStudentsForTa'  => ['ar' => 'لا يوجد طلاب مسندون لهذا المعيد في هذه الشعبة.',
                           'en' => 'No students are assigned to this TA in this section.'],
'err.noStudentsMovable'=> ['ar' => 'لا يوجد طلاب قابلون للنقل.',   'en' => 'There are no students eligible for reassignment.'],

/* ------------------------------------------------------------------- users */
'err.nameRequired'     => ['ar' => 'الاسم مطلوب.',                'en' => 'A name is required.'],
'err.badRole'          => ['ar' => 'دور غير معروف.',              'en' => 'Unknown role.'],
'err.badEmail'         => ['ar' => 'البريد الإلكتروني غير صحيح.',  'en' => 'The email address is not valid.'],
'err.userNotFound'     => ['ar' => 'المستخدم غير موجود.',         'en' => 'The user was not found.'],
'err.cantDemoteSelf'   => ['ar' => 'لا يمكنك إزالة صلاحية المشرف العام عن حسابك.',
                           'en' => 'You cannot remove the Main TA role from your own account.'],
'err.needOneMain'      => ['ar' => 'يجب أن يبقى مشرف عام واحد على الأقل.',
                           'en' => 'At least one Main TA must remain.'],
'err.needOneMainActive'=> ['ar' => 'يجب أن يبقى مشرف عام نشط واحد على الأقل.',
                           'en' => 'At least one active Main TA must remain.'],
'err.badUsername'      => ['ar' => 'اسم المستخدم: حروف إنجليزية وأرقام و. _ - من ٣ إلى ٥٠ حرفًا.',
                           'en' => 'Username: Latin letters, digits, and . _ - between 3 and 50 characters.'],
'err.usernameTaken'    => ['ar' => 'اسم المستخدم مستخدم بالفعل.',  'en' => 'That username is already taken.'],
'err.pwShort'          => ['ar' => 'كلمة المرور ٨ أحرف على الأقل.', 'en' => 'The password must be at least 8 characters.'],
'err.cantSuspendSelf'  => ['ar' => 'لا يمكنك إيقاف حسابك الخاص.',  'en' => 'You cannot suspend your own account.'],
'err.badFormat'        => ['ar' => 'صيغة البيانات غير صحيحة.',     'en' => 'The data format is not valid.'],

/* ------------------------------------------------------- import (tools/) */
'imp.title'            => ['ar' => 'استيراد البيانات',            'en' => 'Data import'],
'imp.mainOnly'         => ['ar' => 'هذه الصفحة للمشرف العام فقط.', 'en' => 'This page is restricted to the Main TA.'],
'imp.dashboard'        => ['ar' => 'لوحة المتابعة',               'en' => 'Dashboard'],
'imp.users'            => ['ar' => 'المستخدمون',                  'en' => 'Users'],

'imp.kindTas'          => ['ar' => 'المعيدون',                    'en' => 'Teaching assistants'],
'imp.kindSections'     => ['ar' => 'الشعب',                       'en' => 'Sections'],
'imp.kindStudents'     => ['ar' => 'الطلاب',                      'en' => 'Students'],

'imp.statTas'          => ['ar' => 'معيد',                        'en' => 'Assistants'],
'imp.statSections'     => ['ar' => 'شعبة',                        'en' => 'Sections'],
'imp.statStudents'     => ['ar' => 'طالب',                        'en' => 'Students'],
'imp.statGrades'       => ['ar' => 'درجة',                        'en' => 'Grades'],

'imp.what'             => ['ar' => 'ما الذي تستورده',             'en' => 'What are you importing?'],
'imp.columns'          => ['ar' => 'الأعمدة:',                    'en' => 'Columns:'],
'imp.sectionsHint'     => ['ar' => 'عمود tas يحتوي أسماء مستخدمي المعيدين مفصولة بالرمز |',
                           'en' => 'The tas column lists assistant usernames separated by |'],
'imp.tasHint'          => ['ar' => 'اترك عمود password فارغًا لتُولَّد كلمة مرور وتُعرض عليك مرة واحدة. البريد الإلكتروني اختياري، لكن بدونه لن تصل الإشعارات.',
                           'en' => 'Leave the password column empty to have one generated and shown to you once. Email is optional, but without it notifications cannot be delivered.'],
'imp.headerNote'       => ['ar' => 'سطر العناوين اختياري. ترتيب السطور في الملف هو ترتيب الطلاب الذي يتبعه توزيع المعيدين.',
                           'en' => 'A header row is optional. The order of rows in the file is the student order the distribution follows.'],

'imp.upload'           => ['ar' => 'ارفع ملف CSV',                'en' => 'Upload a CSV file'],
'imp.paste'            => ['ar' => 'أو الصق المحتوى',             'en' => 'Or paste the content'],
'imp.wipe'             => ['ar' => 'احذف طلاب هذه الشعب أولًا (للاستيراد الكامل)',
                           'en' => 'Delete the students of these sections first (for a full replacement)'],
'imp.check'            => ['ar' => 'فحص فقط',                     'en' => 'Validate only'],
'imp.run'              => ['ar' => 'استيراد',                     'en' => 'Import'],
'imp.warnWipe'         => ['ar' => 'خيار حذف الطلاب يزيل الطلاب ودرجاتهم معًا. خذ نسخة احتياطية قبل استخدامه.',
                           'en' => 'Deleting students removes their recorded grades along with them. Take a backup first.'],

'imp.problems'         => ['ar' => '{n} مشكلة — لم يُسجَّل شيء حتى تُصحَّح.',
                           'en' => '{n} problem(s) — nothing was saved until they are corrected.'],
'imp.recorded'         => ['ar' => 'سُجِّل {n} من {label}.',       'en' => '{n} {label} recorded.'],
'imp.ready'            => ['ar' => 'الفحص سليم: {n} سطرًا جاهزًا. اضغط «استيراد».',
                           'en' => 'Validation passed: {n} rows ready. Press Import.'],
'imp.newPasswords'     => ['ar' => 'كلمات المرور الجديدة — انسخها الآن',
                           'en' => 'New passwords — copy them now'],
'imp.onceOnly'         => ['ar' => 'لن تظهر مرة أخرى.',           'en' => 'They will not be shown again.'],

'imp.row'              => ['ar' => 'سطر {n}',                     'en' => 'Row {n}'],
'imp.errTaRequired'    => ['ar' => 'اسم المستخدم والاسم مطلوبان.', 'en' => 'Username and name are required.'],
'imp.errBadUsername'   => ['ar' => 'اسم مستخدم غير صالح ({v}).',   'en' => 'Invalid username ({v}).'],
'imp.errBadEmail'      => ['ar' => 'بريد إلكتروني غير صالح ({v}).', 'en' => 'Invalid email address ({v}).'],
'imp.errSecRequired'   => ['ar' => 'الرمز والاسم مطلوبان.',        'en' => 'Code and name are required.'],
'imp.errStuRequired'   => ['ar' => 'الرقم الجامعي والاسم والشعبة مطلوبة.',
                           'en' => 'Student ID, name, and section are required.'],
'imp.errNoSection'     => ['ar' => 'الشعبة «{v}» غير موجودة — استورد الشعب أولًا.',
                           'en' => 'Section “{v}” does not exist — import sections first.'],
'imp.errNoTa'          => ['ar' => 'المعيد «{v}» غير موجود — استورد المعيدين أولًا.',
                           'en' => 'Assistant “{v}” does not exist — import assistants first.'],
'imp.errStopped'       => ['ar' => 'توقّف الاستيراد عند: {v}',     'en' => 'The import stopped at: {v}'],

/* --------------------------------------------- enrolment export import */
'imp.kindEnrol'        => ['ar' => 'كشف التسجيل',                 'en' => 'Enrolment export'],
'imp.enrolHint'        => ['ar' => 'ارفع كشف التسجيل كما يصدر من الجامعة (احفظه من Excel بصيغة CSV). يُقرأ سطر العناوين تلقائيًا، وتُؤخذ صفوف CSE014 المعملية فقط — باقي المقررات تُتجاهل. الشعب تُنشأ أو تُحدَّث من الملف نفسه (اليوم والوقت والقاعة).',
                            'en' => 'Upload the university enrolment export (save it from Excel as CSV). The header row is detected automatically and only CSE014 lab rows are taken; every other course is ignored. Sections are created or updated from the file itself (day, time, room).'],
'imp.enrolNoHeader'    => ['ar' => 'تعذّر العثور على سطر العناوين — لا بد أن يحتوي الملف على أعمدة ID و Name و Full Course Code.',
                            'en' => 'Could not find the header row — the file must contain ID, Name, and Full Course Code columns.'],
'imp.enrolNoRows'      => ['ar' => 'لا توجد صفوف CSE014 معملية في هذا الملف.',
                            'en' => 'No CSE014 lab rows were found in this file.'],
'imp.enrolSummary'     => ['ar' => 'قُرئ {scanned} سطرًا · {found} صف CSE014 معملي · {sections} شعبة',
                            'en' => '{scanned} rows read · {found} CSE014 lab rows · {sections} sections'],
'imp.enrolAdded'       => ['ar' => 'طلاب جدد: {n}',                'en' => 'New students: {n}'],
'imp.enrolMoved'       => ['ar' => 'غيّروا الشعبة: {n}',           'en' => 'Changed section: {n}'],
'imp.enrolSame'        => ['ar' => 'بدون تغيير: {n}',              'en' => 'Unchanged: {n}'],
'imp.enrolGone'        => ['ar' => 'مسجّلون عندنا وغير موجودين في الملف: {n}',
                            'en' => 'In the system but absent from the file: {n}'],
'imp.enrolGoneNote'    => ['ar' => 'لم يُحذف أحد. راجع القائمة — قد يكونون انسحبوا من المقرر. الحذف يتم يدويًا لأنه يمحو درجاتهم المسجلة معهم.',
                            'en' => 'Nobody was removed. Review the list — they may have withdrawn. Removal is manual because it deletes their recorded grades with them.'],
'imp.enrolSecNew'      => ['ar' => 'شعب جديدة: {n}',               'en' => 'New sections: {n}'],
'imp.enrolSecNoTa'     => ['ar' => 'شعب بدون معيدين — طلابها لن يُسند إليهم أحد حتى تعيّن لها معيدين من صفحة المستخدمين: {list}',
                            'en' => 'Sections with no assistants — their students stay unassigned until you set assistants on the Users page: {list}'],

/* ------------------------------------------------------------------ device */
'dev.unknown'          => ['ar' => 'جهاز',                        'en' => 'Device'],
'dev.android'          => ['ar' => 'أندرويد',                     'en' => 'Android'],
'dev.iphone'           => ['ar' => 'آيفون',                       'en' => 'iPhone'],
'dev.ipad'             => ['ar' => 'آيباد',                       'en' => 'iPad'],
'dev.windows'          => ['ar' => 'ويندوز',                      'en' => 'Windows'],
'dev.mac'              => ['ar' => 'ماك',                         'en' => 'macOS'],
'dev.linux'            => ['ar' => 'لينكس',                       'en' => 'Linux'],

/* ------------------------------------------------------------------- email */
'mail.newRequestSubj'  => ['ar' => 'طلب تعديل درجة — {lab}',
                           'en' => 'Grade amendment request — {lab}'],
'mail.newRequestHead'  => ['ar' => 'طلب تعديل درجة',              'en' => 'Grade amendment request'],
'mail.decidedSubjOk'   => ['ar' => 'تمت الموافقة على طلبك — {lab}',
                           'en' => 'Your request was approved — {lab}'],
'mail.decidedSubjNo'   => ['ar' => 'لم تتم الموافقة على طلبك — {lab}',
                           'en' => 'Your request was not approved — {lab}'],
'mail.greeting'        => ['ar' => 'مرحبًا {name}،',              'en' => 'Hello {name},'],
'mail.fromTa'          => ['ar' => 'مقدّم الطلب',                 'en' => 'Requested by'],
'mail.student'         => ['ar' => 'الطالب',                      'en' => 'Student'],
'mail.section'         => ['ar' => 'الشعبة',                      'en' => 'Section'],
'mail.change'          => ['ar' => 'التغيير',                     'en' => 'Change'],
'mail.reason'          => ['ar' => 'السبب',                       'en' => 'Reason'],
'mail.decision'        => ['ar' => 'القرار',                      'en' => 'Decision'],
'mail.approved'        => ['ar' => 'تمت الموافقة',                'en' => 'Approved'],
'mail.rejected'        => ['ar' => 'مرفوض',                       'en' => 'Rejected'],
'mail.rejectReason'    => ['ar' => 'سبب الرفض',                   'en' => 'Reason for rejection'],
'mail.openDashboard'   => ['ar' => 'فتح لوحة المتابعة',           'en' => 'Open the dashboard'],
'mail.footer'          => ['ar' => 'هذه رسالة آلية من نظام تقييم معامل CSE014.',
                           'en' => 'This is an automated message from the CSE014 Lab Assessment System.'],
];
