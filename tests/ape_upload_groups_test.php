<?php
// Run: php tests/ape_upload_groups_test.php
// Read-only CTE fixtures shadow the three APE tables for each SELECT.
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/ApeWorkflow.php';

function expect(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$db = auth_db();
$seed = $db->query('SELECT * FROM ape_records ORDER BY ape_id LIMIT 1')->fetch();
expect((bool) $seed, 'One existing APE identity is needed for read-only join fixtures.');
$seed = array_replace($seed, ['exam_date'=>'2026-09-03', 'patient_vitals_status'=>'Confirmed', 'workflow_status'=>'Follow-up Required', 'clearance_status'=>'For Follow-up', 'follow_up_required'=>1, 'requirement_status'=>'Not Checked', 'requirements_saved_at'=>'2026-09-03 14:00:00', 'follow_up_due_date'=>'2026-09-15']);
$apeId = (int) $seed['ape_id'];
$requirements = [];
foreach (['Custom Initial A', 'Custom Initial B', 'Deferred TB Cert'] as $i => $name) {
    $requirements[] = ['requirement_id'=>$i+1, 'ape_id'=>$apeId, 'requirement_name'=>$name, 'status'=>$i===2?'Missing':'Verified', 'remarks'=>$i===2?'Return certificate':null, 'upload_group'=>$i===2?'follow_up':'initial', 'upload_due_date'=>$i===2?'2026-09-15':'2026-09-10'];
}
function cteRows(array $rows): string {
    global $db;
    $selects=[];
    foreach ($rows as $row) {
        $values=[];
        foreach ($row as $key=>$value) $values[]=($value===null?'NULL':(is_int($value)?(string)$value:$db->quote((string)$value))).' AS `'.$key.'`';
        $selects[]='SELECT '.implode(', ', $values);
    }
    return implode(' UNION ALL ', $selects);
}
function upload(int $id, string $name, string $status): array {
    global $apeId;
    return ['document_id'=>$id,'ape_id'=>$apeId,'document_type'=>$name,'verification_status'=>$status,'file_path'=>'fixture-only','uploaded_at'=>'2026-09-04 08:00:00','verified_at'=>null,'verified_by_person_id'=>null];
}
function fixtureRecord(array $requirements, array $documents=[], array $overrides=[]): array {
    global $db,$seed;
    $docSql=cteRows($documents ?: [upload(0,'None','Pending')]);
    if (!$documents) $docSql.=' WHERE 1=0';
    $sql='WITH ape_records AS ('.cteRows([array_replace($seed,$overrides)]).'), ape_requirements AS ('.cteRows($requirements).'), ape_documents AS ('.$docSql.') '.ape_record_select_sql();
    $rows=$db->query($sql)->fetchAll();
    expect(count($rows)===1,'Fixture must return one record.');
    return $rows[0];
}
$r=fixtureRecord($requirements);
expect((int)$r['initial_requirement_count']===2 && (int)$r['deferred_requirement_count']===1,'Dynamic groups include custom requirements.');
expect(ape_record_queue($r)==='digital_submission','Missing initial files stay in digital keeping.');
expect(ape_deadline_status($r)['due_date']==='2026-09-10','Initial group has examination +7 days.');
$initial=[upload(101,'Custom Initial A','Verified'),upload(102,'Custom Initial B','Verified')];
expect(ape_record_queue(fixtureRecord($requirements,[$initial[0]]))==='digital_submission','Partial initial upload cannot advance.');
$pending=$initial; $pending[1]['verification_status']='Pending';
expect(ape_record_queue(fixtureRecord($requirements,$pending))==='digital_submission','Archive review required.');
$r=fixtureRecord($requirements,$initial);
expect(ape_record_queue($r)==='follow_up','Initial archive advances without deferred file.');
expect(ape_deadline_status($r)['due_date']==='2026-09-15','Follow-up retains assigned deadline.');
foreach (['2000-01-01', date('Y-m-d'), '2099-12-31'] as $deadlineDate) {
    $reviewable = array_replace($r, ['follow_up_due_date'=>$deadlineDate]);
    expect(ape_can_review_returned_documents($reviewable), 'Review is available before, on, and after the deadline: '.$deadlineDate);
}
expect(ape_can_review_returned_documents(array_replace($r, ['requirements_saved_at'=>null])), 'Old unlocked records can use direct uploaded-file review.');
expect(!ape_can_review_returned_documents(array_replace($r, ['exam_date'=>null])), 'Examination remains a prerequisite.');
expect(!ape_can_review_returned_documents(array_replace($r, ['required_document_count'=>0])), 'Initial archive remains a prerequisite.');
expect(!ape_can_review_returned_documents(array_replace($r, ['clearance_status'=>'Cleared'])), 'Completed record cannot be reopened.');
$earlyReturn = array_replace($r, ['follow_up_due_date'=>'2026-09-04', 'deferred_upload_due_date'=>'2026-09-04']);
foreach (['2026-09-03', '2026-09-04', '2026-09-05', '2026-09-09', '2026-09-10'] as $day) {
    $deadline = ape_deadline_status($earlyReturn, new DateTimeImmutable($day));
    expect($deadline['label'] === 'On Track', 'No document warning before the full examination week passes: '.$day);
    expect($deadline['due_date'] === '2026-09-04', 'Warning grace must not change the saved return date.');
}
expect(ape_deadline_status($earlyReturn, new DateTimeImmutable('2026-09-11'))['label'] === 'Overdue', 'Warn after examination +7 days.');
expect(ape_deadline_status($r, new DateTimeImmutable('2026-09-14'))['label'] === 'On Track', 'No due-tomorrow urgent warning.');
expect(ape_deadline_status($r, new DateTimeImmutable('2026-09-15'))['label'] === 'On Track', 'Allow the entire assigned due date.');
expect(ape_deadline_status($r, new DateTimeImmutable('2026-09-16'))['label'] === 'Overdue', 'Warn after a later assigned due date passes.');
$initialWaiting = fixtureRecord($requirements);
expect(ape_deadline_status($initialWaiting, new DateTimeImmutable('2026-09-10'))['label'] === 'On Track', 'Initial upload deadline day is not overdue.');
expect(ape_deadline_status($initialWaiting, new DateTimeImmutable('2026-09-11'))['label'] === 'Overdue', 'Initial uploads warn after their seven-day deadline.');
$old=upload(99,'Custom Initial B','Needs Correction');
expect(ape_digital_submission_complete(fixtureRecord($requirements,[$old,...$initial])),'Latest verified version supersedes older correction.');
expect(!ape_digital_submission_complete(fixtureRecord($requirements,[...$initial,upload(103,'Custom Initial B','Needs Correction')])),'Latest correction blocks initial archive completion.');
$returned=['requirement_status'=>'Checked','workflow_status'=>'Reviewed','clearance_status'=>'Pending','follow_up_required'=>0];
expect(ape_record_queue(fixtureRecord($requirements,$initial,$returned))==='follow_up','Deferred upload still needed after return review.');
expect(ape_record_queue(fixtureRecord($requirements,[...$initial,upload(104,'Deferred TB Cert','Pending')],$returned))==='follow_up','Deferred file needs archive review.');
expect(ape_record_queue(fixtureRecord($requirements,[...$initial,upload(104,'Deferred TB Cert','Verified')],$returned))==='final_decision','All archived can reach final decision.');
expect(ape_record_queue(fixtureRecord(array_slice($requirements,0,2),$initial,$returned))==='final_decision','No deferred group: initial archive reaches final decision.');
$allDeferred=$requirements; foreach($allDeferred as &$req)$req['upload_group']='follow_up'; unset($req);
expect(ape_record_queue(fixtureRecord($allDeferred))==='follow_up','No initial documents means nothing to wait for in initial group.');
$unassigned=$requirements; $unassigned[0]['upload_group']=null;
expect(!ape_digital_submission_complete(fixtureRecord($unassigned,$initial)),'Unassigned legacy group must not silently advance.');
expect(ape_record_queue(fixtureRecord($requirements,$initial,['exam_date'=>null]))==='examination','Exam prerequisite preserved.');

class GroupTestStatement extends PDOStatement {
    public function __construct(private GroupTestPDO $owner, private string $sql) {}
    public function execute(?array $params=null): bool { $this->owner->calls[]=[$this->sql,$params]; return true; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->owner->rows; }
}
class GroupTestPDO extends PDO {
    public array $calls=[];
    public function __construct(public array $rows=[]) {}
    public function prepare(string $query, array $options=[]): PDOStatement|false { return new GroupTestStatement($this,$query); }
}
$fake=new GroupTestPDO();
$plan=ape_hard_copy_review_plan($requirements,'follow_up',[3],'Return certificate');
ape_apply_hard_copy_review($fake,$apeId,1,$plan,'2026-09-03','2026-09-15');
expect(count($fake->calls)===3,'Review updates each requirement once.');
expect($fake->calls[0][1][6]==='2026-09-10' && $fake->calls[0][1][7]==='initial','Initial deadline/group saved.');
expect($fake->calls[2][1][4]===1 && $fake->calls[2][1][5]==='2026-09-15' && $fake->calls[2][1][7]==='follow_up','Deferred assigned date/group saved.');
expect(str_contains($fake->calls[0][0],'upload_group = COALESCE(upload_group, ?)'),'Return review preserves original group.');

// Exercise the production archive branch with a fake connection, replacing only its requirement fetch.
$source=file_get_contents(__DIR__.'/../public/ape/view.php');
$start=strpos($source,'$archiveQueue = ape_record_queue($record);');
$end=strpos($source,"} elseif (\$action === 'request_document_correction')",$start);
$archive=str_replace(['ape_requirements_for_record($id)', 'ape_findings_for_record($id)'], ['$requirements', '$findingsFixture'], substr($source,$start,$end-$start));
$canRecordApeExam=true; $findingsFixture=[];
$record=fixtureRecord($requirements,$pending); $id=$apeId; $staffPersonId=1;
$apeDb=new GroupTestPDO($pending); eval($archive);
$writes=array_values(array_filter($apeDb->calls,static fn($call)=>str_starts_with($call[0],'UPDATE')));
expect(count($writes)===2,'Archive changes files and workflow only, not saved checklist.');
expect($writes[0][1]===[$staffPersonId,$id,101,102],'Initial archive targets only initial file IDs.');
expect($writes[1][1][0]==='Follow-up Required','Follow-up flag retained after initial archive.');
$record=fixtureRecord($requirements,[...$initial,upload(104,'Deferred TB Cert','Pending')],$returned);
$apeDb=new GroupTestPDO([...$initial,upload(104,'Deferred TB Cert','Pending')]); eval($archive);
$writes=array_values(array_filter($apeDb->calls,static fn($call)=>str_starts_with($call[0],'UPDATE')));
expect($writes[0][1]===[$staffPersonId,$id,104],'Deferred archive targets only returned group.');
expect(count($writes)===3 && $writes[2][1]===[0,'Reviewed','Pending',$staffPersonId,$id], 'Deferred archive verifies requirements and moves to final decision.');
expect(str_contains($writes[1][0], "upload_group = 'follow_up'") && $writes[1][1]===[$staffPersonId,$id,'Deferred TB Cert'], 'Only the deferred requirement is accepted; deadlines and remarks remain unchanged.');
foreach ([null, '2026-09-03 14:00:00'] as $savedAt) {
    $record=fixtureRecord($requirements,[...$initial,upload(104,'Deferred TB Cert','Pending')],['requirements_saved_at'=>$savedAt]);
    $apeDb=new GroupTestPDO([...$initial,upload(104,'Deferred TB Cert','Pending')]); eval($archive);
    $writes=array_values(array_filter($apeDb->calls,static fn($call)=>str_starts_with($call[0],'UPDATE')));
    expect($writes[2][1][0]===0, 'Direct archive works without a separate hard-copy review, including old unlocked record.');
}
$findingsFixture=[['follow_up_required'=>1]];
$apeDb=new GroupTestPDO([...$initial,upload(104,'Deferred TB Cert','Pending')]); eval($archive);
$writes=array_values(array_filter($apeDb->calls,static fn($call)=>str_starts_with($call[0],'UPDATE')));
expect($writes[2][1][0]===1 && $writes[2][1][1]==='Follow-up Required','Clinical follow-up remains open.');
$findingsFixture=[];
foreach (['missing', 'correction', 'hard_copy_pending'] as $case) {
    $record=fixtureRecord($requirements,$pending);
    $rows=$pending;
    if($case==='missing') array_pop($rows);
    if($case==='correction') $rows[1]['verification_status']='Needs Correction';
    if($case==='hard_copy_pending') $record=fixtureRecord($requirements,$initial);
    $apeDb=new GroupTestPDO($rows); $rejected=false;
    try { eval($archive); } catch(RuntimeException $e) { $rejected=true; }
    expect($rejected, 'Reject archive: '.$case);
    expect(!array_filter($apeDb->calls,static fn($call)=>str_starts_with($call[0],'UPDATE')), 'Rejected archive makes no writes.');
}

function e($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
$renderStart=strpos($source,'function render_ape_hard_copy_review_fields(');
$renderEnd=strpos($source,'$record = fetch_ape_record($id);',$renderStart);
eval(substr($source,$renderStart,$renderEnd-$renderStart));
$record=fixtureRecord($requirements);
ob_start(); render_ape_hard_copy_review_fields($requirements,'follow_up',$record); $html=ob_get_clean();
expect(str_contains($html,'Sep 10, 2026') && str_contains($html,'Sep 15, 2026'),'Clinic summary shows both deadlines.');
expect(str_contains($html,'Deferred TB Cert') && !str_contains($html,'Custom Initial A'),'Saved summary keeps only selected documents.');
expect(!preg_match('/<(input|select|textarea)\b/',$html),'Saved summary stays locked.');
$record['requirements_saved_at']=null;
ob_start(); render_ape_hard_copy_review_fields($requirements,'follow_up',$record); $html=ob_get_clean();
expect(str_contains($html,'Deferred TB Cert') && !preg_match('/<(input|select|textarea)\b/',$html),'Old unlocked saved examination renders a read-only summary.');

// Render the actual uploaded-file panel, not a duplicate test template.
$record=fixtureRecord($requirements,[...$initial,upload(104,'Deferred TB Cert','Pending')],['requirements_saved_at'=>null]);
$queueKey=ape_record_queue($record);
$reviewDocuments=[upload(100,'Deferred TB Cert','Needs Correction'),upload(104,'Deferred TB Cert','Pending'),...$initial];
foreach($reviewDocuments as &$doc)$doc['original_filename']='fixture.pdf'; unset($doc);
$setupStart=strpos($source,"\$reviewUploadGroup = \$queueKey");
$setupEnd=strpos($source,'$showExamForm =',$setupStart);
eval(substr($source,$setupStart,$setupEnd-$setupStart));
expect(count($reviewDocuments)===1 && $reviewDocuments[0]['document_id']===104,'Review shows only latest file in follow-up group.');
$panelStart=strpos($source,"<?php elseif (\$queueKey === 'digital_submission'");
$panelEnd=strpos($source,"<?php elseif (\$queueKey === 'examination'",$panelStart);
$panel=substr($source,$panelStart,$panelEnd-$panelStart);
$panel=preg_replace('/<\?php elseif /','<?php if ',$panel,1).'<?php endif; ?>';
ob_start(); eval('?>'.$panel); $html=ob_get_clean();
expect(str_contains($html,'Uploaded Follow-up Documents') && str_contains($html,'View File') && str_contains($html,'Archive Documents'),'Direct preview/archive UI renders for outstanding hard-copy follow-up.');
expect(!str_contains($html,'hard_copy_status') && !str_contains($html,'Save Document Review'),'No checklist save in uploaded-file panel.');
$dom=new DOMDocument(); libxml_use_internal_errors(true); $dom->loadHTML($html); libxml_clear_errors();
$xpath=new DOMXPath($dom);
expect($xpath->query('//form[input[@value="approve_documents"]]/button[not(@disabled)]')->length===1,'Archive button enabled for complete pending uploads.');
$reviewGroupUploaded=false;
ob_start(); eval('?>'.$panel); $partial=ob_get_clean();
$dom->loadHTML($partial); libxml_clear_errors();
$xpath=new DOMXPath($dom);
expect(str_contains($partial,'View File') && $xpath->query('//form[input[@value="approve_documents"]]/button[@disabled]')->length===1,'Partial uploads remain previewable but cannot archive prematurely.');
$portal=file_get_contents(__DIR__.'/../patient-portal/patient-ape-status.php');
$cardStart=strpos($portal,'$requirementByName = array_column($requirements');
$cardEnd=strpos($portal,'$uploadableDocumentCount =',$cardStart);
$documents=[['name'=>'Custom Initial A'],['name'=>'Deferred TB Cert']];
eval(substr($portal,$cardStart,$cardEnd-$cardStart));
expect($documents[0]['upload_due_date']==='2026-09-10' && $documents[1]['upload_due_date']==='2026-09-15','Patient cards receive individual deadlines.');
echo "PASS: query groups, deadlines, latest-version gates, empty/legacy cases, review save, initial and deferred archive. No patient writes.\n";
