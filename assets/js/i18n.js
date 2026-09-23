/* ==========================================================================
   Marking — Internationalisation layer (Arabic / English)
   --------------------------------------------------------------------------
   Loaded from <head> on every page, before any other script, so that the
   document direction is settled before first paint.

   Markup is translated declaratively:

       <h1 data-i18n="login.heading"></h1>
       <input data-i18n-ph="stu.search">
       <button data-i18n-aria="adm.rubricDelAria">

   Strings built in script go through t('key', { n: 3 }).

   The chosen language is mirrored into a cookie so that server-rendered
   pages (tools/import.php) and API error messages match the interface.
   ========================================================================== */

const I18N = (() => {

  const STORE_KEY = 'marking.lang';
  const COOKIE    = 'marking_lang';
  const SUPPORTED = ['ar', 'en'];
  const FALLBACK  = 'ar';

  /* ======================================================================
     Dictionary
     ====================================================================== */
  const DICT = {

  /* ---------------------------------------------------------------- brand */
  'app.name':        { ar: 'نظام تقييم المعامل',        en: 'Lab Assessment System' },
  'app.course':      { ar: 'البرمجة المهيكلة',          en: 'Structured Programming' },
  'app.term':        { ar: 'الفصل الدراسي الأول ٢٠٢٦',  en: 'Fall 2026' },
  'app.courseCode':  { ar: 'CSE014',                     en: 'CSE014' },

  /* ---------------------------------------------------------------- roles */
  'role.main':       { ar: 'المشرف العام',               en: 'Main TA' },

  /* ------------------------------------------------------------- language */
  'lang.switchTo':   { ar: 'English',                    en: 'العربية' },
  'lang.switchAria': { ar: 'Switch to English',          en: 'التبديل إلى العربية' },

  /* -------------------------------------------------------------- actions */
  'act.save':        { ar: 'حفظ',                        en: 'Save' },
  'act.saving':      { ar: 'جارٍ الحفظ…',                en: 'Saving…' },
  'act.saved':       { ar: 'تم الحفظ',                   en: 'Saved' },
  'act.cancel':      { ar: 'إلغاء',                      en: 'Cancel' },
  'act.close':       { ar: 'إغلاق',                      en: 'Close' },
  'act.next':        { ar: 'التالي',                     en: 'Next' },
  'act.back':        { ar: 'رجوع',                       en: 'Back' },
  'act.add':         { ar: 'إضافة',                      en: 'Add' },
  'act.edit':        { ar: 'تعديل',                      en: 'Edit' },
  'act.copy':        { ar: 'نسخ',                        en: 'Copy' },
  'act.copied':      { ar: 'تم النسخ',                   en: 'Copied' },
  'act.copyFail':    { ar: 'اضغط مطولًا ثم اختر «نسخ»',   en: 'Press and hold, then choose Copy' },
  'act.retry':       { ar: 'إعادة المحاولة',             en: 'Retry' },
  'act.dismiss':     { ar: 'تجاهل',                      en: 'Dismiss' },
  'act.approve':     { ar: 'موافقة',                     en: 'Approve' },
  'act.reject':      { ar: 'رفض',                        en: 'Reject' },
  'act.signIn':      { ar: 'تسجيل الدخول',               en: 'Sign in' },
  'act.signOut':     { ar: 'تسجيل الخروج',               en: 'Sign out' },
  'act.revert':      { ar: 'تراجع',                      en: 'Revert' },

  /* --------------------------------------------------------- shared nouns */
  'noun.section':    { ar: 'الشعبة',                     en: 'Section' },
  'noun.student':    { ar: 'الطالب',                     en: 'Student' },
  'noun.grade':      { ar: 'الدرجة',                     en: 'Grade' },
  'noun.attendance': { ar: 'الحضور',                     en: 'Attendance' },
  'noun.rubric':     { ar: 'معايير التقييم',             en: 'Rubric' },

  'lab.n':           { ar: 'المعمل {n}',                 en: 'Lab {n}' },
  'lab.open':        { ar: 'مفتوح',                      en: 'Open' },
  'lab.closed':      { ar: 'مغلق',                       en: 'Closed' },

  'status.present':  { ar: 'حاضر',                       en: 'Present' },
  'status.late':     { ar: 'متأخر',                      en: 'Late' },
  'status.absent':   { ar: 'غائب',                       en: 'Absent' },

  /* ----------------------------------------------------------------- time */
  'time.now':        { ar: 'الآن',                       en: 'Just now' },
  'time.minsAgo':    { ar: 'منذ {n} دقيقة',              en: '{n} min ago' },
  'time.today':      { ar: 'اليوم {t}',                  en: 'Today {t}' },
  'time.yesterday':  { ar: 'أمس {t}',                    en: 'Yesterday {t}' },
  'time.never':      { ar: 'لم يسجّل الدخول بعد',         en: 'Never signed in' },
  'time.lastSeen':   { ar: 'آخر دخول {t}',               en: 'Last seen {t}' },

  /* ---------------------------------------------------------------- steps */
  'step.section':    { ar: 'الشعبة',                     en: 'Section' },
  'step.student':    { ar: 'الطالب',                     en: 'Student' },
  'step.grade':      { ar: 'الدرجة',                     en: 'Grade' },

  /* ---------------------------------------------------------------- login */
  'login.pageTitle': { ar: 'تسجيل الدخول',               en: 'Sign in' },
  'login.heading':   { ar: 'تسجيل الدخول',               en: 'Sign in' },
  'login.lede':      { ar: 'سجّل الدخول ببيانات حسابك للبدء في تقييم المعامل.',
                       en: 'Sign in with your account credentials to begin marking.' },
  'login.username':  { ar: 'اسم المستخدم',               en: 'Username' },
  'login.password':  { ar: 'كلمة المرور',                en: 'Password' },
  'login.working':   { ar: 'جارٍ التحقق…',               en: 'Verifying…' },
  'login.help':      { ar: 'لطلب حساب أو استعادة كلمة المرور، يُرجى التواصل مع المشرف العام للمقرر.',
                       en: 'To request an account or reset a password, please contact the course Main TA.' },

  /* ------------------------------------------------------------- sections */
  'sec.pageTitle':   { ar: 'اختيار الشعبة',              en: 'Select section' },
  'sec.heading':     { ar: 'الشعب المسندة إليك',         en: 'Your sections' },
  'sec.none':        { ar: 'لا توجد شعب مسندة إلى حسابك.',
                       en: 'No sections are assigned to your account.' },
  'sec.noLab':       { ar: 'لم يفتح المشرف العام أي معمل بعد. لتعديل معمل سابق، استخدم «تعديل معمل مغلق» أدناه.',
                       en: 'The Main TA has not opened a lab yet. To amend an earlier lab, use “Amend a closed lab” below.' },
  'sec.noLabTitle':  { ar: 'لا يوجد معمل مفتوح',          en: 'No lab is open' },
  'sec.choose':      { ar: 'اختر شعبة للمتابعة',          en: 'Select a section to continue' },
  'sec.continueTo':  { ar: 'متابعة · {name}',            en: 'Continue · {name}' },
  'sec.mine':        { ar: 'طلابي',                      en: 'My students' },
  'sec.whole':       { ar: 'الشعبة',                     en: 'Section' },
  'sec.closedNote':  { ar: '{lab} مغلق — كل إدخال يتطلب سببًا مكتوبًا يُرسل إلى المشرف العام.',
                       en: '{lab} is closed — every entry requires a written reason sent to the Main TA.' },
  'sec.amendTitle':  { ar: 'تعديل معمل مغلق',            en: 'Amend a closed lab' },
  'sec.amendNote':   { ar: 'للحالات الاستثنائية فقط. كل درجة تُدخل هنا تتطلب سببًا مكتوبًا يصل إلى المشرف العام.',
                       en: 'For exceptional cases only. Every grade entered here requires a written reason that reaches the Main TA.' },
  'sec.noClosed':    { ar: 'لا توجد معامل مغلقة.',        en: 'There are no closed labs.' },
  'sec.toDashboard': { ar: 'لوحة المتابعة',              en: 'Dashboard' },

  /* ----------------------------------------------- rejected offline queue */
  'dead.title':      { ar: 'إدخالات رفضها الخادم',       en: 'Entries rejected by the server' },
  'dead.body':       { ar: 'أُدخلت هذه الدرجات أثناء انقطاع الاتصال، ورفضها الخادم عند استئنافه. لم تُسجَّل بعد — يُرجى مراجعتها وإعادة إدخالها.',
                       en: 'These grades were entered while offline and were rejected when the connection resumed. They are not recorded — please review and re-enter them.' },
  'dead.confirmClear': { ar: 'ستُتجاهل هذه الإدخالات نهائيًا ولن تُسجَّل. هل تريد المتابعة؟',
                         en: 'These entries will be discarded permanently and will not be recorded. Continue?' },
  'dead.retryResult':  { ar: 'أُرسل {sent} · رُفض {failed}',
                         en: '{sent} sent · {failed} still rejected' },
  'dead.retryOk':      { ar: 'أُرسل {sent}',              en: '{sent} sent' },

  /* ------------------------------------------------------------- requests */
  'req.mine':        { ar: 'طلباتي',                     en: 'My requests' },
  'req.pending':     { ar: 'قيد المراجعة',               en: 'Pending' },
  'req.approved':    { ar: 'مقبول',                      en: 'Approved' },
  'req.rejected':    { ar: 'مرفوض',                      en: 'Rejected' },
  'req.rejectReason':{ ar: 'سبب الرفض: {note}',          en: 'Reason for rejection: {note}' },
  'req.sent':        { ar: 'أُرسل الطلب — {name}',        en: 'Request submitted — {name}' },
  'req.blocked':     { ar: 'يوجد طلب قيد المراجعة',       en: 'A request is pending' },
  'req.submit':      { ar: 'إرسال طلب إلى المشرف العام',  en: 'Submit request to the Main TA' },
  'req.sending':     { ar: 'جارٍ الإرسال…',              en: 'Submitting…' },

  /* -------------------------------------------------------------- student */
  'stu.pageTitle':   { ar: 'اختيار الطالب',              en: 'Select student' },
  'stu.search':      { ar: 'ابحث بالاسم أو الرقم الجامعي أو البريد الإلكتروني',
                       en: 'Search by name, student ID, or email' },
  'stu.filterLeft':  { ar: 'المتبقي لديّ ({n})',          en: 'Remaining ({n})' },
  'stu.filterLeftMain': { ar: 'المتبقي ({n})',            en: 'Remaining ({n})' },
  'stu.filterMine':  { ar: 'طلابي ({n})',                en: 'My students ({n})' },
  'stu.filterAll':   { ar: 'الشعبة بالكامل ({n})',        en: 'Entire section ({n})' },
  'stu.outside':     { ar: 'من خارج الشعبة',             en: 'Outside this section' },
  'stu.noResults':   { ar: 'لا توجد نتائج مطابقة في هذه الشعبة.',
                       en: 'No matching results in this section.' },
  'stu.allDone':     { ar: 'اكتمل تقييم جميع الطلاب المسندين إليك هنا.',
                       en: 'All students assigned to you here have been marked.' },
  'stu.listDone':    { ar: 'اكتملت هذه القائمة',          en: 'This list is complete' },
  'stu.nextIs':      { ar: 'التالي · {name}',            en: 'Next · {name}' },
  'stu.notMarked':   { ar: 'لم يُقيَّم',                  en: 'Not marked' },
  'stu.otherTa':     { ar: 'معيد آخر',                   en: 'Another TA' },
  'stu.assignedOther':{ ar: 'مسند إلى معيد آخر',          en: 'Assigned to another TA' },
  'stu.pendingReq':  { ar: 'طلب قيد المراجعة',           en: 'Request pending' },

  /* ---------------------------------------------------------------- grade */
  'grd.pageTitle':   { ar: 'إدخال الدرجة',               en: 'Enter grade' },
  'grd.outsideSec':  { ar: '{name} — خارج شعبتك',        en: '{name} — outside your section' },
  'grd.reasonEdit':  { ar: 'سبب التعديل',                en: 'Reason for amendment' },
  'grd.reasonReq':   { ar: 'سبب الطلب',                  en: 'Reason for request' },
  'grd.required':    { ar: '— مطلوب',                    en: '— required' },
  'grd.reasonPh':    { ar: 'اختر سببًا من الأعلى أو اكتب سببًا خاصًا',
                       en: 'Choose a reason above, or write your own' },
  'grd.fullMarks':   { ar: 'الدرجة كاملة',               en: 'Full marks' },
  'grd.manual':      { ar: 'إدخال درجة يدويًا',          en: 'Enter grade manually' },
  'grd.needReason':  { ar: 'اكتب السبب أولًا',            en: 'Write the reason first' },
  'grd.saveNext':    { ar: 'حفظ ومتابعة',                en: 'Save and continue' },
  'grd.pendingFlag': { ar: 'يوجد طلب تعديل قيد المراجعة لهذا الطالب من {ta} — في انتظار قرار المشرف العام.',
                       en: 'A change request for this student from {ta} is awaiting the Main TA’s decision.' },
  'grd.takenMain':   { ar: 'الدرجة المسجلة {g}/{max} أدخلها {ta}. يمكنك تعديلها مباشرة، وسيُسجَّل السبب في السجل.',
                       en: 'The recorded grade of {g}/{max} was entered by {ta}. You may amend it directly; the reason will be logged.' },
  'grd.takenTa':     { ar: 'هذا الطالب حاصل على {g}/{max} من {ta}. أرسل طلبًا ليوافق عليه المشرف العام أو يرفضه.',
                       en: 'This student has {g}/{max} from {ta}. Submit a request for the Main TA to approve or reject.' },
  'grd.alreadyMine': { ar: 'سجّلت لهذا الطالب {g}/{max} في {when}. الحفظ سيحدّث الدرجة.',
                       en: 'You recorded {g}/{max} for this student on {when}. Saving will update the grade.' },

  'reason.otherSection':  { ar: 'شعبة أخرى',             en: 'Other section' },
  'reason.otherSectionT': { ar: 'حضر معي في شعبة أخرى خلال الأسبوع نفسه وسلّم العمل أمامي.',
                            en: 'Attended another section with me in the same week and submitted the work in my presence.' },
  'reason.repeated':      { ar: 'أعاد المعمل',            en: 'Repeated the lab' },
  'reason.repeatedT':     { ar: 'أعاد المعمل معي وأكمل الجزء الناقص.',
                            en: 'Repeated the lab with me and completed the missing part.' },
  'reason.entryError':    { ar: 'خطأ في الإدخال',         en: 'Data-entry error' },
  'reason.entryErrorT':   { ar: 'الدرجة المسجلة بها خطأ في الإدخال، وهذه هي الدرجة الصحيحة.',
                            en: 'The recorded grade contains a data-entry error; this is the correct grade.' },
  'reason.excused':       { ar: 'تأخر بعذر',              en: 'Excused lateness' },
  'reason.excusedT':      { ar: 'سلّم متأخرًا بعذر مقبول ومعتمد.',
                            en: 'Submitted late with an approved excuse.' },
  'reason.myError':       { ar: 'خطأ مني',                en: 'My error' },
  'reason.myErrorT':      { ar: 'خطأ من جانبي عند إدخال الدرجة لأول مرة.',
                            en: 'An error on my part when the grade was first entered.' },
  'reason.appeal':        { ar: 'تظلّم الطالب',           en: 'Student appeal' },
  'reason.appealT':       { ar: 'إعادة تقييم بناءً على تظلّم الطالب.',
                            en: 'Re-assessment following a student appeal.' },
  'reason.reviewed':      { ar: 'مراجعة شخصية',          en: 'Personal review' },
  'reason.reviewedT':     { ar: 'راجعت عمل الطالب بنفسي بعد تظلّمه.',
                            en: 'I reviewed the student’s work personally after their appeal.' },
  'reason.markingError':  { ar: 'خطأ في التقييم',         en: 'Assessment error' },
  'reason.markingErrorT': { ar: 'خطأ في التقييم جرى التحقق منه.',
                            en: 'A verified error in the assessment.' },
  'reason.administrative':{ ar: 'قرار إداري',            en: 'Administrative decision' },
  'reason.administrativeT':{ ar: 'تعديل بقرار من المشرف العام.',
                            en: 'Amended by decision of the Main TA.' },

  /* ------------------------------------------------------------ dashboard */
  'adm.pageTitle':   { ar: 'لوحة المتابعة',              en: 'Dashboard' },
  'adm.tabWatch':    { ar: 'المتابعة',                   en: 'Monitor' },
  'adm.tabTerm':     { ar: 'الفصل الدراسي',              en: 'Term' },
  'adm.tabLog':      { ar: 'السجلات',                    en: 'Logs' },
  'adm.users':       { ar: 'المستخدمون',                 en: 'Users' },
  'adm.import':      { ar: 'الاستيراد',                  en: 'Import' },

  'adm.labScheduled':{ ar: 'وفق الجدول · {w}',           en: 'Per schedule · {w}' },
  'adm.labManual':   { ar: '{state} يدويًا — الجدول يحدد {w}',
                       en: '{state} manually — the schedule specifies {w}' },
  'adm.openLab':     { ar: 'فتح المعمل',                 en: 'Open lab' },
  'adm.closeLab':    { ar: 'إغلاق المعمل',               en: 'Close lab' },
  'adm.backToSched': { ar: 'إعادة إلى الجدول',           en: 'Revert to schedule' },
  'adm.editGrade':   { ar: 'تعديل درجة طالب',            en: 'Amend a student grade' },
  'adm.labOpened':   { ar: 'فُتح {lab}',                 en: '{lab} opened' },
  'adm.labClosed':   { ar: 'أُغلق {lab}',                en: '{lab} closed' },
  'adm.reverted':    { ar: 'أُعيد إلى الجدول',            en: 'Reverted to the schedule' },

  'adm.reqTitle':    { ar: 'طلبات في انتظار قرارك',      en: 'Requests awaiting your decision' },
  'adm.reqCount':    { ar: '{n} طلب',                    en: '{n} pending' },
  'adm.enableNotif': { ar: 'تفعيل الإشعارات',            en: 'Enable notifications' },
  'adm.notifOn':     { ar: 'فُعّلت الإشعارات',            en: 'Notifications enabled' },
  'adm.notifOff':    { ar: 'لم يُسمح بالإشعارات',         en: 'Notification permission denied' },
  'adm.notifNA':     { ar: 'المتصفح لا يسمح بالإشعارات هنا',
                       en: 'The browser does not permit notifications here' },
  'adm.notifTitle':  { ar: 'طلبات تعديل جديدة',          en: 'New change requests' },
  'adm.notifBody':   { ar: '{n} طلب في انتظار قرارك',     en: '{n} request(s) awaiting your decision' },
  'adm.reqLine':     { ar: 'طلب {ta} تعديل درجة {student}',
                       en: '{ta} requested an amendment to {student}’s grade' },
  'adm.reqCurrentBy':{ ar: 'الدرجة الحالية من {ta}',      en: 'Current grade by {ta}' },
  'adm.rejectPh':    { ar: 'سبب الرفض — سيصل إلى المعيد',
                       en: 'Reason for rejection — sent to the TA' },
  'adm.confirmReject':{ ar: 'تأكيد الرفض',               en: 'Confirm rejection' },
  'adm.needRejectNote':{ ar: 'اكتب سبب الرفض ليتمكن المعيد من فهم القرار.',
                         en: 'Provide a reason so the TA can understand the decision.' },
  'adm.approved':    { ar: 'تمت الموافقة — عُدّلت الدرجة',
                       en: 'Approved — the grade has been updated' },
  'adm.rejectedMsg': { ar: 'تم الرفض — الدرجة دون تغيير',
                       en: 'Rejected — the grade is unchanged' },

  'adm.statTaPending':{ ar: 'معيد لم يبدأ',              en: 'TAs not started' },
  'adm.statSecPending':{ ar: 'شعبة لم تبدأ',             en: 'Sections not started' },
  'adm.statCompletion':{ ar: 'اكتمال {lab} · {done}/{total}',
                         en: '{lab} completion · {done}/{total}' },

  'adm.taSection':   { ar: 'المعيدون',                   en: 'Teaching assistants' },
  'adm.taHint':      { ar: 'من لم يبدأ يظهر أولًا',       en: 'Those yet to start appear first' },
  'adm.taDone':      { ar: 'مكتمل',                      en: 'Complete' },
  'adm.taWorking':   { ar: 'قيد العمل',                  en: 'In progress' },
  'adm.taPending':   { ar: 'لم يبدأ',                    en: 'Not started' },
  'adm.taEntries':   { ar: '{n} إدخال · {when}',          en: '{n} entries · {when}' },
  'adm.taNoSections':{ ar: 'لا توجد شعب مسندة',          en: 'No sections assigned' },

  'adm.distTitle':   { ar: 'من المسؤول عن من',            en: 'Who marks whom' },
  'adm.distHint':    { ar: 'توزيع الطلاب على المعيدين في {lab} — يُحتسب ويتغيّر كل معمل',
                       en: 'How students are distributed across assistants for {lab} — recomputed each lab' },
  'adm.distShow':    { ar: 'عرض الطلاب',                  en: 'Show students' },
  'adm.distHide':    { ar: 'إخفاء الطلاب',                en: 'Hide students' },
  'adm.distCount':   { ar: '{done}/{total} مُقيَّم',       en: '{done}/{total} marked' },
  'adm.distNone':    { ar: 'لا يوجد طلاب مسندون إليه في هذا المعمل.',
                       en: 'No students are assigned to them for this lab.' },
  'adm.distBy':      { ar: 'سجّلها {ta}',                 en: 'entered by {ta}' },
  'adm.distUnassigned': { ar: 'طلاب بدون معيد مسؤول',     en: 'Students with no assistant' },
  'adm.distUnassignedHint': { ar: 'شعبهم ليس لها معيدون — عيّنهم من صفحة المستخدمين',
                              en: 'Their sections have no assistants — assign them from the Users page' },
  'adm.distCopy':    { ar: 'نسخ التوزيع',                 en: 'Copy distribution' },
  'adm.reassign':    { ar: 'نقل طلاب معيد',              en: 'Reassign a TA’s students' },
  'adm.reassignHint':{ ar: '— عند غياب أحد المعيدين',     en: '— when a TA is absent' },
  'adm.fromTa':      { ar: 'من المعيد',                  en: 'From TA' },
  'adm.toTa':        { ar: 'إلى المعيد',                 en: 'To TA' },
  'adm.onlyUnmarked':{ ar: 'الطلاب الذين لم يُقيَّموا فقط',
                       en: 'Only students not yet marked' },
  'adm.doReassign':  { ar: 'تنفيذ النقل',                en: 'Reassign' },
  'adm.clearReassign':{ ar: 'استعادة التوزيع الأصلي',     en: 'Restore original distribution' },
  'adm.moved':       { ar: 'نُقل {n} طالبًا',             en: '{n} student(s) reassigned' },
  'adm.restored':    { ar: 'استُعيد التوزيع الأصلي',      en: 'Original distribution restored' },

  'adm.matrixTitle': { ar: 'نظرة عامة على الفصل',        en: 'Term overview' },
  'adm.matrixHint':  { ar: 'نسبة الاكتمال لكل شعبة في كل معمل',
                       en: 'Completion rate for each section in each lab' },

  'adm.exportTitle': { ar: 'تصدير الدرجات',              en: 'Export grades' },
  'adm.exportHint':  { ar: 'ملف CSV يفتح مباشرة في Excel',
                       en: 'A CSV file that opens directly in Excel' },
  'adm.exportLab':   { ar: 'المعمل المحدد',              en: 'Selected lab' },
  'adm.exportAll':   { ar: 'جميع المعامل',               en: 'All labs' },
  'adm.exportSaved': { ar: 'نُزّل الملف {f}. إذا لم تجده، انسخ المحتوى أدناه واحفظه في ملف بامتداد csv.',
                       en: '{f} has been downloaded. If you cannot find it, copy the content below into a .csv file.' },
  'adm.exportManual':{ ar: 'التنزيل غير متاح هنا — انسخ المحتوى واحفظه في ملف باسم {f}',
                       en: 'Download is unavailable here — copy the content into a file named {f}' },
  'adm.copyAll':     { ar: 'نسخ الكل',                   en: 'Copy all' },

  'adm.schedTitle':  { ar: 'جدول المعامل',               en: 'Lab schedule' },
  'adm.schedHint':   { ar: '— الفتح والإغلاق التلقائي',   en: '— automatic opening and closing' },
  'adm.schedStart':  { ar: 'تاريخ المعمل الأول',          en: 'Date of Lab 1' },
  'adm.schedDays':   { ar: 'مدة فتح المعمل بالأيام',      en: 'Days each lab stays open' },
  'adm.schedSave':   { ar: 'حفظ الجدول',                 en: 'Save schedule' },
  'adm.schedAuto':   { ar: 'تفعيل الفتح والإغلاق التلقائي',
                       en: 'Automatic opening and closing enabled' },
  'adm.schedSaved':  { ar: 'حُفظ الجدول',                en: 'Schedule saved' },
  'adm.colLab':      { ar: 'المعمل',                     en: 'Lab' },
  'adm.colFrom':     { ar: 'من',                         en: 'From' },
  'adm.colTo':       { ar: 'إلى',                        en: 'To' },
  'adm.colState':    { ar: 'الحالة',                     en: 'State' },
  'adm.colSource':   { ar: 'المصدر',                     en: 'Source' },
  'adm.srcManual':   { ar: 'يدوي',                       en: 'Manual' },
  'adm.srcSchedule': { ar: 'الجدول',                     en: 'Schedule' },

  'adm.rubricTitle': { ar: 'معايير تقييم المعمل',         en: 'Lab rubric' },
  'adm.rubricSum':   { ar: '— {lab} · {n} بندًا · المجموع {total}',
                       en: '— {lab} · {n} criteria · total {total}' },
  'adm.rubricItemPh':{ ar: 'نص البند',                   en: 'Criterion text' },
  'adm.rubricAdd':   { ar: '+ بند',                      en: '+ Criterion' },
  'adm.rubricTotal': { ar: 'المجموع {n}',                en: 'Total {n}' },
  'adm.rubricRecalc':{ ar: 'إعادة احتساب الدرجات المسجلة من البنود',
                       en: 'Recalculate recorded grades from the criteria' },
  'adm.rubricSave':  { ar: 'حفظ المعايير',               en: 'Save rubric' },
  'adm.rubricNote':  { ar: 'مجموع النقاط هو الدرجة النهائية للمعمل. عند تغيير النقاط مع وجود درجات مسجلة، أبقِ خيار إعادة الاحتساب مفعّلًا لتُحدَّث الدرجات من البنود المخزّنة. الدرجات اليدوية وحالات الغياب لا تتأثر.',
                       en: 'The sum of the points is the lab’s final grade. When points change and grades already exist, keep recalculation enabled so grades are updated from the stored criteria. Manual grades and absences are unaffected.' },
  'adm.rubricDelAria':{ ar: 'حذف البند',                 en: 'Remove criterion' },
  'adm.rubricMinOne':{ ar: 'يجب أن تحتوي المعايير على بند واحد على الأقل.',
                       en: 'The rubric must contain at least one criterion.' },
  'adm.rubricNoText':{ ar: 'يوجد بند بدون نص.',          en: 'A criterion has no text.' },
  'adm.rubricZero':  { ar: 'يجب أن يكون مجموع النقاط أكبر من صفر.',
                       en: 'The total points must be greater than zero.' },
  'adm.rubricSaved': { ar: 'حُفظت المعايير',              en: 'Rubric saved' },
  'adm.rubricSavedN':{ ar: 'حُفظت المعايير · أُعيد احتساب {n} درجة',
                       en: 'Rubric saved · {n} grade(s) recalculated' },

  'adm.ovrTitle':    { ar: 'سجل تعديلات الدرجات',        en: 'Grade amendment log' },
  'adm.ovrHint':     { ar: 'كل درجة خرجت عن المسار المعتاد، مع سببها',
                       en: 'Every grade that departed from the normal path, with its reason' },
  'adm.ovrLine':     { ar: 'عدّل {ta} درجة {student}',    en: '{ta} amended {student}’s grade' },
  'adm.ovrApproved': { ar: 'بموافقتك',                   en: 'With your approval' },
  'adm.ovrByMain':   { ar: 'تعديل المشرف العام',          en: 'Main TA amendment' },
  'adm.ovrAfterClose':{ ar: 'بعد الإغلاق',               en: 'After closing' },
  'adm.ovrEmpty':    { ar: 'لا توجد تعديلات حتى الآن.',   en: 'No amendments yet.' },
  'adm.ovrGrade':    { ar: 'الدرجة {g}',                 en: 'Grade {g}' },

  'adm.feedTitle':   { ar: 'آخر النشاط',                 en: 'Recent activity' },
  'adm.feedHint':    { ar: 'من أدخل درجات ومتى',          en: 'Who entered grades, and when' },
  'adm.feedVerb':    { ar: 'قيّم',                        en: 'marked' },
  'adm.feedAmend':   { ar: 'تعديل',                      en: 'Amended' },
  'adm.feedEmpty':   { ar: 'لا يوجد نشاط حتى الآن.',      en: 'No activity yet.' },

  'adm.auditTitle':  { ar: 'سجل الإجراءات الإدارية',      en: 'Administrative action log' },
  'adm.auditHint':   { ar: 'حالة المعامل والمعايير ونقل الطلاب وإدارة الحسابات',
                       en: 'Lab state, rubric changes, reassignment, and account administration' },
  'adm.auditEmpty':  { ar: 'لا توجد إجراءات مسجلة حتى الآن.',
                       en: 'No recorded actions yet.' },

  /* ---------------------------------------------------------- audit verbs */
  'audit.lab.open':       { ar: 'فتح {target}',                    en: 'Opened {target}' },
  'audit.lab.close':      { ar: 'إغلاق {target}',                  en: 'Closed {target}' },
  'audit.lab.schedule':   { ar: 'إعادة {target} إلى الجدول',        en: 'Reverted {target} to the schedule' },
  'audit.rubric.save':    { ar: 'تعديل معايير {target}',            en: 'Updated the rubric for {target}' },
  'audit.schedule.save':  { ar: 'تعديل جدول المعامل',              en: 'Updated the lab schedule' },
  'audit.roster.reassign':{ ar: 'نقل طلاب في {target}',             en: 'Reassigned students in {target}' },
  'audit.roster.clear':   { ar: 'استعادة التوزيع الأصلي في {target}',
                            en: 'Restored the original distribution in {target}' },
  'audit.request.approve':{ ar: 'الموافقة على طلب تعديل {target}',  en: 'Approved a change request for {target}' },
  'audit.request.reject': { ar: 'رفض طلب تعديل {target}',           en: 'Rejected a change request for {target}' },
  'audit.user.create':    { ar: 'إنشاء حساب {target}',              en: 'Created the account {target}' },
  'audit.user.update':    { ar: 'تعديل حساب {target}',              en: 'Updated the account {target}' },
  'audit.user.password':  { ar: 'إعادة تعيين كلمة مرور {target}',   en: 'Reset the password for {target}' },
  'audit.user.activate':  { ar: 'تفعيل حساب {target}',              en: 'Activated the account {target}' },
  'audit.user.deactivate':{ ar: 'إيقاف حساب {target}',              en: 'Deactivated the account {target}' },
  'audit.user.sections':  { ar: 'تعديل شعب {target}',               en: 'Updated section assignments for {target}' },
  'audit.import.run':     { ar: 'استيراد بيانات ({target})',        en: 'Imported data ({target})' },

  /* ----------------------------------------------------------- user admin */
  'usr.pageTitle':   { ar: 'إدارة المستخدمين',           en: 'User administration' },
  'usr.addTitle':    { ar: 'إضافة مستخدم',               en: 'Add a user' },
  'usr.addNew':      { ar: '+ مستخدم جديد',              en: '+ New user' },
  'usr.username':    { ar: 'اسم المستخدم',               en: 'Username' },
  'usr.fullName':    { ar: 'الاسم الكامل',               en: 'Full name' },
  'usr.email':       { ar: 'البريد الإلكتروني',           en: 'Email address' },
  'usr.emailHint':   { ar: '(للإشعارات)',                en: '(for notifications)' },
  'usr.password':    { ar: 'كلمة المرور',                en: 'Password' },
  'usr.passwordHint':{ ar: '(اتركها فارغة لتُولَّد تلقائيًا)',
                       en: '(leave blank to generate one)' },
  'usr.passwordPh':  { ar: 'تُولَّد تلقائيًا',            en: 'Generated automatically' },
  'usr.isMain':      { ar: 'مشرف عام (صلاحيات كاملة)',    en: 'Main TA (full privileges)' },
  'usr.pwTitle':     { ar: 'كلمة المرور',                en: 'Password' },
  'usr.pwOnce':      { ar: 'لن تظهر مرة أخرى — سلّمها لصاحب الحساب الآن.',
                       en: 'This will not be shown again — pass it to the account holder now.' },
  'usr.accounts':    { ar: 'الحسابات',                   en: 'Accounts' },
  'usr.countLine':   { ar: '{n} حساب · {active} نشط',     en: '{n} accounts · {active} active' },
  'usr.suspended':   { ar: 'موقوف',                      en: 'Suspended' },
  'usr.you':         { ar: 'أنت',                        en: 'You' },
  'usr.entries':     { ar: '{n} إدخال',                  en: '{n} entries' },
  'usr.noEmail':     { ar: 'لا يوجد بريد إلكتروني — لن تصله الإشعارات',
                       en: 'No email address — notifications will not reach them' },
  'usr.noSections':  { ar: 'لا توجد شعب مسندة',          en: 'No sections assigned' },
  'usr.actSections': { ar: 'الشعب',                      en: 'Sections' },
  'usr.actPassword': { ar: 'كلمة مرور جديدة',            en: 'New password' },
  'usr.actSuspend':  { ar: 'إيقاف',                      en: 'Suspend' },
  'usr.actActivate': { ar: 'تفعيل',                      en: 'Activate' },
  'usr.notifyOn':    { ar: 'يتلقى إشعارات بالبريد الإلكتروني',
                       en: 'Receives email notifications' },
  'usr.secPanelHint':{ ar: 'الشعب التي يتولاها المعيد. يُحتسب توزيع الطلاب بناءً عليها.',
                       en: 'The sections this TA is responsible for. Student distribution is derived from them.' },
  'usr.secSaved':    { ar: '{n} شعبة',                   en: '{n} section(s)' },
  'usr.confirmPw':   { ar: 'إصدار كلمة مرور جديدة لـ {name}؟ لن تعمل كلمة المرور الحالية بعد ذلك.',
                       en: 'Issue a new password for {name}? The current password will stop working.' },
  'usr.confirmOff':  { ar: 'إيقاف حساب {name}؟ لن يتمكن من تسجيل الدخول، وتبقى درجاته المسجلة كما هي.',
                       en: 'Suspend {name}? They will not be able to sign in; their recorded grades remain unchanged.' },
  'usr.suspendedMsg':{ ar: 'أُوقف الحساب',                en: 'Account suspended' },
  'usr.activatedMsg':{ ar: 'فُعّل الحساب',                en: 'Account activated' },

  'log.title':       { ar: 'سجل تسجيل الدخول',           en: 'Sign-in log' },
  'log.all':         { ar: 'الكل',                       en: 'All' },
  'log.failed':      { ar: 'المحاولات الفاشلة',          en: 'Failed attempts' },
  'log.ok':          { ar: 'نجح',                        en: 'Success' },
  'log.fail':        { ar: 'فشل',                        en: 'Failed' },
  'log.empty':       { ar: 'لا يوجد سجل حتى الآن.',       en: 'No entries yet.' },

  /* ----------------------------------------------------------- connection */
  'net.offlineQueued':{ ar: 'لا يوجد اتصال — حُفظ {n} إدخال على هذا الجهاز وسيُرسل تلقائيًا عند عودة الشبكة',
                        en: 'Offline — {n} entries saved on this device; they will be sent when the network returns' },
  'net.offline':     { ar: 'لا يوجد اتصال — تُحفظ الإدخالات على هذا الجهاز وتُرسل تلقائيًا',
                       en: 'Offline — entries are saved on this device and sent automatically' },
  'net.syncing':     { ar: 'عاد الاتصال — جارٍ إرسال {n}…',
                       en: 'Back online — sending {n}…' },
  'net.rejected':    { ar: '{n} إدخال رفضه الخادم — راجعها في صفحة الشعب',
                       en: '{n} entries rejected by the server — review them on the sections page' },
  'net.wentOffline': { ar: 'انقطع الاتصال — تابع العمل، لن تُفقد أي درجة',
                       en: 'Connection lost — carry on; no grade will be lost' },
  'net.restored':    { ar: 'عاد الاتصال وأُرسلت جميع الإدخالات',
                       en: 'Connection restored; all entries have been sent' },
  'net.noServer':    { ar: 'تعذّر الاتصال بالخادم — يُرجى المحاولة عند عودة الشبكة.',
                       en: 'Could not reach the server — please try again when the network returns.' },
  'net.serverRefused':{ ar: 'رفض الخادم هذه العملية.',    en: 'The server refused this operation.' },

  /* -------------------------------------------------------- file:// guard */
  'file.title':      { ar: 'فُتحت الصفحة من الملف مباشرة',
                       en: 'This page was opened directly from a file' },
  'file.body':       { ar: 'يعمل هذا النظام بلغة PHP، ولذلك يجب فتحه من خلال خادم لا بالنقر المزدوج على الملف. يقرأ المتصفح الملف الآن كنص، ولن يعمل تسجيل الدخول.',
                       en: 'This system runs on PHP and must be served by a web server rather than opened by double-clicking the file. The browser is reading the file as text, so signing in will not work.' },
  'file.fix':        { ar: 'الحل:',                      en: 'To fix this:' },
  'file.fixBody':    { ar: 'شغّل خادمًا من مجلد المشروع:', en: 'Start a server from the project directory:' },
  'file.then':       { ar: 'ثم افتح',                    en: 'then open' },
  'file.xampp':      { ar: 'أو ضع المجلد داخل htdocs في XAMPP وافتحه من العنوان أدناه بعد تشغيل Apache.',
                       en: 'Alternatively, place the folder inside XAMPP’s htdocs and open the address below after starting Apache.' }
  };

  /* ======================================================================
     Engine
     ====================================================================== */

  function readCookie(name) {
    const m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }

  /**
   * The URL path this installation is served from, with a trailing slash.
   *
   * The application is often deployed inside a larger site, so the cookie is
   * scoped here rather than to the whole domain. Pages under tools/ share the
   * application root, which keeps the choice consistent across them.
   * Mirrors app_base_path() in api/_bootstrap.php.
   */
  function basePath() {
    const dir = location.pathname.replace(/[^/]*$/, '');   // drop the file name
    return dir.replace(/(?:api|tools|db)\/$/, '') || '/';
  }

  function writeCookie(name, value) {
    const parts = [
      name + '=' + encodeURIComponent(value),
      'path=' + basePath(),
      'max-age=' + 60 * 60 * 24 * 365,
      'SameSite=Lax'
    ];
    if (location.protocol === 'https:') parts.push('Secure');
    document.cookie = parts.join('; ');
  }

  function detect() {
    let stored = null;
    try { stored = localStorage.getItem(STORE_KEY); } catch {}
    const candidate = stored || readCookie(COOKIE)
      || (navigator.language || '').slice(0, 2).toLowerCase();
    return SUPPORTED.includes(candidate) ? candidate : FALLBACK;
  }

  let lang = detect();
  const dir = () => (lang === 'ar' ? 'rtl' : 'ltr');

  /* Applied before first paint, so the layout never flips visibly. */
  function applyDocumentAttributes() {
    const html = document.documentElement;
    html.setAttribute('lang', lang);
    html.setAttribute('dir', dir());
  }
  applyDocumentAttributes();
  writeCookie(COOKIE, lang);

  /**
   * A server-rendered page (tools/import.php) declares the language it was
   * built with via data-server-lang. If the reader has since chosen the other
   * one, reload now that the cookie agrees — once only, so a server that
   * cannot honour the choice never sends us into a loop.
   */
  (function reconcileServerLang() {
    const served = document.documentElement.dataset.serverLang;
    if (!served) return;

    const KEY = 'marking.lang.reloaded';
    const flag = {
      get()   { try { return sessionStorage.getItem(KEY) === '1'; } catch { return false; } },
      set()   { try { sessionStorage.setItem(KEY, '1'); } catch {} },
      clear() { try { sessionStorage.removeItem(KEY); } catch {} }
    };

    if (served === lang) { flag.clear(); return; }
    if (flag.get()) return;
    flag.set();
    location.reload();
  })();

  function t(key, vars) {
    const entry = DICT[key];
    let s = entry ? (entry[lang] ?? entry[FALLBACK]) : key;
    if (vars) s = s.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m));
    return s;
  }

  /* Translate everything declarative inside `root`. */
  function apply(root = document) {
    root.querySelectorAll('[data-i18n]').forEach(el => {
      el.textContent = t(el.dataset.i18n);
    });
    root.querySelectorAll('[data-i18n-html]').forEach(el => {
      el.innerHTML = t(el.dataset.i18nHtml);
    });
    root.querySelectorAll('[data-i18n-ph]').forEach(el => {
      el.setAttribute('placeholder', t(el.dataset.i18nPh));
    });
    root.querySelectorAll('[data-i18n-title]').forEach(el => {
      el.setAttribute('title', t(el.dataset.i18nTitle));
    });
    root.querySelectorAll('[data-i18n-aria]').forEach(el => {
      el.setAttribute('aria-label', t(el.dataset.i18nAria));
    });
    if (root === document) {
      const key = document.documentElement.dataset.titleKey;
      if (key) document.title = t('app.courseCode') + ' · ' + t(key);
    }
  }

  function set(next) {
    if (!SUPPORTED.includes(next) || next === lang) return;
    lang = next;
    try { localStorage.setItem(STORE_KEY, next); } catch {}
    writeCookie(COOKIE, next);
    // Reloading is the honest way to re-render every script-built fragment,
    // and the query string carries the marking flow's state, so nothing is lost.
    location.reload();
  }

  /* Renders the toggle into every [data-lang-switch] placeholder. */
  function mountSwitches(root = document) {
    root.querySelectorAll('[data-lang-switch]').forEach(el => {
      if (el.dataset.mounted) return;
      el.dataset.mounted = '1';
      el.type = 'button';
      el.classList.add('langbtn');
      el.textContent = t('lang.switchTo');
      el.setAttribute('aria-label', t('lang.switchAria'));
      el.addEventListener('click', () => set(lang === 'ar' ? 'en' : 'ar'));
    });
  }

  function init() {
    applyDocumentAttributes();
    apply(document);
    mountSwitches(document);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  return {
    get lang() { return lang; },
    get dir()  { return dir(); },
    get isRtl(){ return lang === 'ar'; },
    t, apply, set, mountSwitches, supported: SUPPORTED
  };
})();

/* Short alias — used heavily by page scripts. */
const t = (key, vars) => I18N.t(key, vars);
