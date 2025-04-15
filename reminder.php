<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/wechat.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

try {
    $db = getDB();
    
    // 获取需要发送提醒的课程
    $now = new DateTime();
    $remindTime = $now->format('Y-m-d H:i:00');
    
    $query = $db->prepare("
        SELECT c.id AS course_id, c.course_name, c.teacher_name, c.location, 
               cs.day_of_week, cs.start_period, cs.period_count, cs.start_week, cs.end_week, cs.odd_even,
               lt.start_time, lt.end_time, lt.period_type,
               rs.user_id, rs.remind_before,
               wu.openid
        FROM reminder_settings rs
        JOIN courses c ON rs.course_id = c.id
        JOIN course_schedules cs ON cs.course_id = c.id
        JOIN lesson_times lt ON lt.period = cs.start_period AND lt.period_type = cs.period_type AND lt.user_id = rs.user_id
        JOIN wechat_users wu ON wu.user_id = rs.user_id AND wu.subscribe = 1
        WHERE rs.is_active = 1
        AND DATE_ADD(
            STR_TO_DATE(CONCAT(
                CASE 
                    WHEN DAYOFWEEK(CURDATE()) <= cs.day_of_week THEN CURDATE()
                    ELSE DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                END, ' ', lt.start_time),
            INTERVAL (cs.day_of_week - DAYOFWEEK(CURDATE())) DAY
        ) = DATE_SUB(?, INTERVAL rs.remind_before MINUTE)
        AND (cs.odd_even = 'all' OR 
             (cs.odd_even = 'odd' AND WEEK(?, 1) % 2 = 1) OR 
             (cs.odd_even = 'even' AND WEEK(?, 1) % 2 = 0))
    ");
    
    $currentDate = $now->format('Y-m-d');
    $query->bind_param('sss', $remindTime, $currentDate, $currentDate);
    $query->execute();
    $result = $query->get_result();
    $courses = $result->fetch_all(MYSQLI_ASSOC);
    
    // 初始化微信服务
    $wechat = new WeChatService('你的APPID', '你的APPSECRET');
    
    $sentCount = 0;
    $errors = [];
    
    foreach ($courses as $course) {
        try {
            // 准备模板消息数据
            $startTime = new DateTime($course['start_time']);
            $endTime = new DateTime($course['end_time']);
            
            $messageData = [
                'first' => ['value' => '课程提醒', 'color' => '#173177'],
                'keyword1' => ['value' => $course['course_name'], 'color' => '#173177'],
                'keyword2' => ['value' => $startTime->format('H:i') . '-' . $endTime->format('H:i'), 'color' => '#173177'],
                'keyword3' => ['value' => $course['location'], 'color' => '#173177'],
                'keyword4' => ['value' => $course['teacher_name'], 'color' => '#173177'],
                'remark' => ['value' => '请提前做好准备！', 'color' => '#173177']
            ];
            
            // 发送微信模板消息
            $wechat->sendTemplateMessage(
                $course['openid'],
                '你的模板消息ID',
                $messageData,
                'https://你的域名/course/timetable.php' // 点击跳转到课表页面
            );
            
            $sentCount++;
            
            // 记录发送日志
            $logStmt = $db->prepare("
                INSERT INTO reminder_logs 
                (user_id, course_id, remind_time, send_time, status)
                VALUES (?, ?, ?, NOW(), 'sent')
            ");
            $logStmt->bind_param('iis', $course['user_id'], $course['course_id'], $remindTime);
            $logStmt->execute();
            
        } catch (Exception $e) {
            $errors[] = "课程ID {$course['course_id']} 发送失败: " . $e->getMessage();
            
            // 记录失败日志
            $logStmt = $db->prepare("
                INSERT INTO reminder_logs 
                (user_id, course_id, remind_time, send_time, status, error_message)
                VALUES (?, ?, ?, NOW(), 'failed', ?)
            ");
            $errorMsg = substr($e->getMessage(), 0, 255);
            $logStmt->bind_param('iiss', $course['user_id'], $course['course_id'], $remindTime, $errorMsg);
            $logStmt->execute();
        }
    }
    
    echo json_encode([
        'status' => 'success',
        'sent_count' => $sentCount,
        'total_courses' => count($courses),
        'errors' => $errors
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
