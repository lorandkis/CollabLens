<?php
$dsn = 'mysql:host=db;dbname=myapp;charset=utf8';
$user = 'appuser';
$pass = 'apppass';

try {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS professors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            department VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS students (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id VARCHAR(50) UNIQUE NOT NULL,
            email VARCHAR(255) UNIQUE NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            discord_user_id VARCHAR(50) UNIQUE,
            discord_username VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            professor_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            discord_server_id VARCHAR(50) UNIQUE,
            sharepoint_site_id VARCHAR(100),
            sharepoint_folder_id VARCHAR(100),
            status ENUM('active', 'completed', 'archived') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (professor_id) REFERENCES professors(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS `groups` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assignment_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            discord_channel_id VARCHAR(50) UNIQUE,
            sharepoint_folder_id VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS group_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id INT NOT NULL,
            student_id INT NOT NULL,
            role ENUM('member', 'leader') DEFAULT 'member',
            joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
            UNIQUE KEY unique_group_student (group_id, student_id)
        );

        CREATE TABLE IF NOT EXISTS discord_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id VARCHAR(50) UNIQUE NOT NULL,
            channel_id VARCHAR(50) NOT NULL,
            author_id VARCHAR(50) NOT NULL,
            content TEXT,
            timestamp TIMESTAMP NOT NULL,
            group_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS sharepoint_activities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            file_id VARCHAR(100) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            activity_type ENUM('created', 'modified', 'deleted', 'shared') NOT NULL,
            user_id VARCHAR(100) NOT NULL,
            user_email VARCHAR(255),
            folder_id VARCHAR(100),
            group_id INT,
            timestamp TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assignment_id INT NOT NULL,
            report_type ENUM('collaboration', 'participation', 'file_activity', 'summary') NOT NULL,
            content JSON,
            generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
        );
    ");

    $pdo->exec("INSERT IGNORE INTO professors (email, password_hash, first_name, last_name, department) VALUES
        ('john.smith@university.edu', 'hashed_password_123', 'John', 'Smith', 'Computer Science');

        INSERT IGNORE INTO students (student_id, email, first_name, last_name, discord_user_id, discord_username) VALUES
        ('S2025001', 'student1@university.edu', 'Megan', 'Chang', 'discord001', 'user1'),
        ('S2025002', 'student2@university.edu', 'Robert', 'Green', 'discord002', 'user2'),
        ('S2025003', 'student3@university.edu', 'William', 'Sullivan', 'discord003', 'user3'),
        ('S2025004', 'student4@university.edu', 'Kristen', 'Turner', 'discord004', 'user4'),
        ('S2025005', 'student5@university.edu', 'Thomas', 'Silva', 'discord005', 'user5'),
        ('S2025006', 'student6@university.edu', 'Rebecca', 'Wagner', 'discord006', 'user6'),
        ('S2025007', 'student7@university.edu', 'Juan', 'Campos', 'discord007', 'user7'),
        ('S2025008', 'student8@university.edu', 'Christine', 'King', 'discord008', 'user8'),
        ('S2025009', 'student9@university.edu', 'Renee', 'Mcgrath', 'discord009', 'user9'),
        ('S2025010', 'student10@university.edu', 'Lisa', 'Barrera', 'discord010', 'user10'),
        ('S2025011', 'student11@university.edu', 'Kyle', 'Blair', 'discord011', 'user11'),
        ('S2025012', 'student12@university.edu', 'Rachel', 'Sutton', 'discord012', 'user12');

        INSERT IGNORE INTO assignments (professor_id, title, description, discord_server_id, sharepoint_site_id, sharepoint_folder_id) VALUES
        (1, 'AI Group Project', 'In this project, students will work collaboratively to develop an AI-powered solution that enhances group productivity and communication. The project will utilize Microsoft SharePoint for document collaboration and data management, while Discord will serve as the primary communication and coordination platform.', 'server001', 'sp_site_001', 'sp_folder_001');

        INSERT IGNORE INTO `groups` (assignment_id, name, discord_channel_id, sharepoint_folder_id) VALUES
        (1, 'Group A', 'channel001', 'sp_group_1'),
        (1, 'Group B', 'channel002', 'sp_group_2'),
        (1, 'Group C', 'channel003', 'sp_group_3');

        INSERT IGNORE INTO group_members (group_id, student_id, role) VALUES
        (1, 7, 'leader'),
        (1, 12, 'member'),
        (1, 1, 'member'),
        (1, 5, 'member'),
        (2, 9, 'leader'),
        (2, 8, 'member'),
        (2, 7, 'member'),
        (2, 5, 'member'),
        (3, 8, 'leader'),
        (3, 6, 'member'),
        (3, 10, 'member'),
        (3, 4, 'member');


        INSERT IGNORE INTO discord_messages (message_id, channel_id, author_id, content, timestamp, group_id) VALUES
        ('msg_0001', 'channel001', 'discord001', 'Nice government first policy daughter need kind.', '2025-06-21 01:50:30', 1),
        ('msg_0002', 'channel002', 'discord002', 'Artist truth trouble behavior style.', '2025-05-26 19:45:08', 2),
        ('msg_0003', 'channel003', 'discord003', 'Management test during foot that course nothing draw.', '2025-06-02 16:26:19', 3),
        ('msg_0004', 'channel001', 'discord004', 'Language ball floor meet usually board necessary.', '2025-06-01 19:50:09', 1),
        ('msg_0005', 'channel002', 'discord005', 'Four two data son natural explain before.', '2025-06-14 10:51:47', 2),
        ('msg_0006', 'channel003', 'discord006', 'Offer face country cost party prevent.', '2025-06-18 21:37:10', 3),
        ('msg_0007', 'channel001', 'discord007', 'Bed serious theory type.', '2025-05-30 15:55:00', 1),
        ('msg_0008', 'channel002', 'discord008', 'Raise study modern miss dog Democrat quickly.', '2025-06-16 03:05:03', 2),
        ('msg_0009', 'channel003', 'discord009', 'Every manage political record word group food break.', '2025-06-24 06:24:44', 3),
        ('msg_0010', 'channel001', 'discord010', 'Friend couple administration even relate head color international.', '2025-05-29 18:52:49', 1),
        ('msg_0011', 'channel002', 'discord011', 'Situation talk despite stage.', '2025-06-21 16:48:23', 2),
        ('msg_0012', 'channel003', 'discord012', 'Quite ago play paper office hospital have wonder.', '2025-05-28 12:49:14', 3),
        ('msg_0013', 'channel001', 'discord001', 'Against which continue buy decision song view age.', '2025-06-16 10:18:09', 1),
        ('msg_0014', 'channel002', 'discord002', 'Big employee determine positive go Congress mean always.', '2025-05-31 16:00:09', 2),
        ('msg_0015', 'channel003', 'discord003', 'Current grow rule stuff truth college.', '2025-06-05 15:24:58', 3),
        ('msg_0016', 'channel001', 'discord004', 'Small citizen class morning.', '2025-06-01 10:59:01', 1),
        ('msg_0017', 'channel002', 'discord005', 'Tv program actually race tonight themselves true power.', '2025-06-14 15:13:18', 2),
        ('msg_0018', 'channel003', 'discord006', 'Check real leader bad school.', '2025-06-22 04:35:56', 3),
        ('msg_0019', 'channel001', 'discord007', 'Care several loss particular pull.', '2025-06-24 10:08:39', 1),
        ('msg_0020', 'channel002', 'discord008', 'Car financial security stock ball organization recognize civil.', '2025-06-06 14:20:55', 2),
        ('msg_0021', 'channel003', 'discord009', 'Her then nothing increase I reduce industry.', '2025-05-29 00:55:23', 3),
        ('msg_0022', 'channel001', 'discord010', 'Knowledge else citizen month.', '2025-06-19 10:07:35', 1),
        ('msg_0023', 'channel002', 'discord011', 'Page a although for study anyone state.', '2025-06-15 22:05:27', 2),
        ('msg_0024', 'channel003', 'discord012', 'Nature white without study candidate.', '2025-05-27 11:34:54', 3),
        ('msg_0025', 'channel001', 'discord001', 'Wear individual about add senior woman.', '2025-06-21 09:59:18', 1),
        ('msg_0026', 'channel002', 'discord002', 'Best budget power them evidence without beyond take.', '2025-06-15 00:36:19', 2),
        ('msg_0027', 'channel003', 'discord003', 'Ball ever laugh society technology card minute practice.', '2025-06-12 14:46:00', 3),
        ('msg_0028', 'channel001', 'discord004', 'The everything affect American.', '2025-06-08 08:57:27', 1),
        ('msg_0029', 'channel002', 'discord005', 'Fire happen nothing support suffer which parent.', '2025-06-19 18:32:18', 2),
        ('msg_0030', 'channel003', 'discord006', 'Policy head Mrs debate onto across character.', '2025-06-11 22:52:11', 3),
        ('msg_0031', 'channel001', 'discord007', 'Smile responsibility full per among clearly.', '2025-06-24 02:11:26', 1),
        ('msg_0032', 'channel002', 'discord008', 'Guess writer can boy room.', '2025-05-29 02:38:36', 2),
        ('msg_0033', 'channel003', 'discord009', 'Conference mission audience idea foreign.', '2025-06-15 21:36:23', 3),
        ('msg_0034', 'channel001', 'discord010', 'Between training listen subject.', '2025-06-12 10:48:14', 1),
        ('msg_0035', 'channel002', 'discord011', 'Look record interview few.', '2025-06-03 07:46:57', 2),
        ('msg_0036', 'channel003', 'discord012', 'Phone heart window police.', '2025-05-29 23:07:25', 3),
        ('msg_0037', 'channel001', 'discord001', 'Cup determine his better.', '2025-06-22 18:45:38', 1),
        ('msg_0038', 'channel002', 'discord002', 'Face turn small research describe base detail.', '2025-06-08 10:24:38', 2),
        ('msg_0039', 'channel003', 'discord003', 'Since issue grow ask tell reduce total later.', '2025-06-05 13:24:15', 3),
        ('msg_0040', 'channel001', 'discord004', 'Market either political young.', '2025-06-06 05:57:21', 1),
        ('msg_0041', 'channel002', 'discord005', 'Chair cup her national character writer work chance.', '2025-06-15 11:20:11', 2),
        ('msg_0042', 'channel003', 'discord006', 'There many true follow marriage material.', '2025-06-05 13:45:27', 3),
        ('msg_0043', 'channel001', 'discord007', 'Particular despite future while together stand along.', '2025-06-02 23:16:50', 1),
        ('msg_0044', 'channel002', 'discord008', 'Owner choose here family relationship.', '2025-06-18 14:30:47', 2),
        ('msg_0045', 'channel003', 'discord009', 'Son might trip at.', '2025-06-02 02:27:27', 3),
        ('msg_0046', 'channel001', 'discord010', 'Feel serve large.', '2025-06-03 13:38:17', 1),
        ('msg_0047', 'channel002', 'discord011', 'Than leave he him.', '2025-06-21 11:41:29', 2),
        ('msg_0048', 'channel003', 'discord012', 'Almost before some military outside baby interview.', '2025-06-05 18:33:01', 3),
        ('msg_0049', 'channel001', 'discord001', 'Movement out stay lot social.', '2025-06-14 15:08:11', 1),
        ('msg_0050', 'channel002', 'discord002', 'Only surface something prevent a consider medical effort.', '2025-05-29 22:52:28', 2),
        ('msg_0051', 'channel003', 'discord003', 'Teacher wall field impact special artist political.', '2025-06-01 22:45:37', 3),
        ('msg_0052', 'channel001', 'discord004', 'Expert stop area along individual.', '2025-06-19 15:26:43', 1),
        ('msg_0053', 'channel002', 'discord005', 'Response purpose character would in partner hit another.', '2025-06-18 15:02:47', 2),
        ('msg_0054', 'channel003', 'discord006', 'After our car food.', '2025-06-22 10:13:10', 3),
        ('msg_0055', 'channel001', 'discord007', 'Crime situation since book art red.', '2025-06-25 14:10:05', 1),
        ('msg_0056', 'channel002', 'discord008', 'Value practice wide require fast support when hold.', '2025-06-10 00:43:36', 2),
        ('msg_0057', 'channel003', 'discord009', 'Million size country site.', '2025-06-17 08:23:37', 3),
        ('msg_0058', 'channel001', 'discord010', 'Series lay smile away and various.', '2025-06-19 08:50:39', 1),
        ('msg_0059', 'channel002', 'discord011', 'Too war project occur democratic.', '2025-05-31 07:19:42', 2),
        ('msg_0060', 'channel003', 'discord012', 'Single recognize information figure box international not type.', '2025-05-31 20:28:49', 3),
        ('msg_0061', 'channel001', 'discord001', 'As indeed choose west social issue.', '2025-06-16 00:17:01', 1),
        ('msg_0062', 'channel002', 'discord002', 'Market ten foot.', '2025-06-08 00:42:17', 2),
        ('msg_0063', 'channel003', 'discord003', 'Good building reality generation.', '2025-06-04 09:41:53', 3),
        ('msg_0064', 'channel001', 'discord004', 'Store discover hand.', '2025-06-24 17:29:50', 1),
        ('msg_0065', 'channel002', 'discord005', 'Debate daughter purpose voice.', '2025-06-01 17:56:43', 2),
        ('msg_0066', 'channel003', 'discord006', 'Fall ready usually.', '2025-06-07 01:43:33', 3),
        ('msg_0067', 'channel001', 'discord007', 'Cost both general where.', '2025-06-02 02:03:55', 1),
        ('msg_0068', 'channel002', 'discord008', 'Whom gun list.', '2025-06-09 23:21:42', 2),
        ('msg_0069', 'channel003', 'discord009', 'View when player contain year.', '2025-05-31 17:56:08', 3),
        ('msg_0070', 'channel001', 'discord010', 'Ok choose today watch source firm drug.', '2025-06-04 16:43:53', 1),
        ('msg_0071', 'channel002', 'discord011', 'Head production technology over hour.', '2025-05-30 07:43:04', 2),
        ('msg_0072', 'channel003', 'discord012', 'Inside nearly scientist central.', '2025-06-16 06:18:22', 3),
        ('msg_0073', 'channel001', 'discord001', 'Pm be know hard we.', '2025-05-29 13:49:45', 1),
        ('msg_0074', 'channel002', 'discord002', 'Impact individual rock fly daughter fall.', '2025-06-18 15:02:40', 2),
        ('msg_0075', 'channel003', 'discord003', 'Wear someone everybody newspaper.', '2025-06-03 05:42:09', 3),
        ('msg_0076', 'channel001', 'discord004', 'Up control instead company where future model.', '2025-06-13 00:27:14', 1),
        ('msg_0077', 'channel002', 'discord005', 'Place beat sense far store last left.', '2025-06-06 09:15:52', 2),
        ('msg_0078', 'channel003', 'discord006', 'Boy without feeling participant interest.', '2025-06-18 15:40:56', 3),
        ('msg_0079', 'channel001', 'discord007', 'Question set discussion seven.', '2025-06-09 08:44:50', 1),
        ('msg_0080', 'channel002', 'discord008', 'Worker building this American either moment ok how.', '2025-05-31 17:17:47', 2),
        ('msg_0081', 'channel003', 'discord009', 'Government the pull cultural be.', '2025-05-30 04:30:27', 3),
        ('msg_0082', 'channel001', 'discord010', 'Figure future morning eat turn.', '2025-06-03 16:58:33', 1),
        ('msg_0083', 'channel002', 'discord011', 'Increase face mind off.', '2025-06-05 23:24:46', 2),
        ('msg_0084', 'channel003', 'discord012', 'Black western myself scientist tough everything kind.', '2025-06-23 08:27:36', 3),
        ('msg_0085', 'channel001', 'discord001', 'Case but building husband life nice federal place.', '2025-06-15 22:03:21', 1),
        ('msg_0086', 'channel002', 'discord002', 'Live reason five present art.', '2025-06-10 08:09:42', 2),
        ('msg_0087', 'channel003', 'discord003', 'Seat appear perform agent.', '2025-06-18 15:47:30', 3),
        ('msg_0088', 'channel001', 'discord004', 'Thousand act money at term rather.', '2025-06-14 22:45:49', 1),
        ('msg_0089', 'channel002', 'discord005', 'Guess break about.', '2025-06-08 21:22:31', 2),
        ('msg_0090', 'channel003', 'discord006', 'Road dinner seem explain black.', '2025-06-18 03:24:08', 3),
        ('msg_0091', 'channel001', 'discord007', 'Himself former possible reach challenge value.', '2025-06-02 22:16:17', 1),
        ('msg_0092', 'channel002', 'discord008', 'Firm decade cost glass work interview man.', '2025-06-06 10:55:30', 2),
        ('msg_0093', 'channel003', 'discord009', 'Keep daughter report town almost.', '2025-05-30 21:03:12', 3),
        ('msg_0094', 'channel001', 'discord010', 'Hair sea quality do.', '2025-06-10 05:03:54', 1),
        ('msg_0095', 'channel002', 'discord011', 'Beautiful than seem sign third in approach recent.', '2025-06-22 07:56:01', 2),
        ('msg_0096', 'channel003', 'discord012', 'Ok majority region democratic entire analysis.', '2025-06-03 16:09:49', 3),
        ('msg_0097', 'channel001', 'discord001', 'About pressure cell skill quite wife.', '2025-06-15 00:23:37', 1),
        ('msg_0098', 'channel002', 'discord002', 'Tv law fund bill third some follow.', '2025-06-17 21:24:05', 2),
        ('msg_0099', 'channel003', 'discord003', 'Eight miss couple bag thank generation.', '2025-06-05 13:19:37', 3),
        ('msg_0100', 'channel001', 'discord004', 'Pull save fine team effort.', '2025-06-12 11:08:25', 1);

        INSERT IGNORE INTO sharepoint_activities (file_id, file_name, activity_type, user_id, user_email, folder_id, group_id, timestamp) VALUES
        ('file_0001', 'try.docx', 'modified', 'discord001', 'student1@university.edu', 'sp_group_1', 1, '2025-06-07 09:59:48'),
        ('file_0002', 'another.docx', 'deleted', 'discord002', 'student2@university.edu', 'sp_group_2', 2, '2025-06-10 14:43:14'),
        ('file_0003', 'world.docx', 'modified', 'discord003', 'student3@university.edu', 'sp_group_3', 3, '2025-06-22 13:31:00'),
        ('file_0004', 'baby.docx', 'created', 'discord004', 'student4@university.edu', 'sp_group_1', 1, '2025-05-27 05:08:58'),
        ('file_0005', 'magazine.docx', 'deleted', 'discord005', 'student5@university.edu', 'sp_group_2', 2, '2025-06-19 19:29:16'),
        ('file_0006', 'shake.docx', 'modified', 'discord006', 'student6@university.edu', 'sp_group_3', 3, '2025-06-17 00:57:33'),
        ('file_0007', 'anyone.docx', 'deleted', 'discord007', 'student7@university.edu', 'sp_group_1', 1, '2025-06-15 18:34:32'),
        ('file_0008', 'minute.docx', 'created', 'discord008', 'student8@university.edu', 'sp_group_2', 2, '2025-06-18 02:57:20'),
        ('file_0009', 'less.docx', 'created', 'discord009', 'student9@university.edu', 'sp_group_3', 3, '2025-06-01 12:02:41'),
        ('file_0010', 'bed.docx', 'deleted', 'discord010', 'student10@university.edu', 'sp_group_1', 1, '2025-05-30 17:20:54'),
        ('file_0011', 'effect.docx', 'shared', 'discord011', 'student11@university.edu', 'sp_group_2', 2, '2025-05-31 13:27:00'),
        ('file_0012', 'the.docx', 'created', 'discord012', 'student12@university.edu', 'sp_group_3', 3, '2025-06-03 05:36:20'),
        ('file_0013', 'job.docx', 'deleted', 'discord001', 'student1@university.edu', 'sp_group_1', 1, '2025-06-06 03:07:38'),
        ('file_0014', 'let.docx', 'shared', 'discord002', 'student2@university.edu', 'sp_group_2', 2, '2025-06-25 12:20:02'),
        ('file_0015', 'ball.docx', 'deleted', 'discord003', 'student3@university.edu', 'sp_group_3', 3, '2025-06-16 11:50:12'),
        ('file_0016', 'operation.docx', 'modified', 'discord004', 'student4@university.edu', 'sp_group_1', 1, '2025-06-14 22:00:46'),
        ('file_0017', 'among.docx', 'shared', 'discord005', 'student5@university.edu', 'sp_group_2', 2, '2025-06-04 12:35:22'),
        ('file_0018', 'enjoy.docx', 'shared', 'discord006', 'student6@university.edu', 'sp_group_3', 3, '2025-06-19 13:19:40'),
        ('file_0019', 'discussion.docx', 'deleted', 'discord007', 'student7@university.edu', 'sp_group_1', 1, '2025-06-01 23:39:04'),
        ('file_0020', 'though.docx', 'created', 'discord008', 'student8@university.edu', 'sp_group_2', 2, '2025-06-09 07:28:29'),
        ('file_0021', 'world.docx', 'created', 'discord009', 'student9@university.edu', 'sp_group_3', 3, '2025-06-12 20:39:04'),
        ('file_0022', 'front.docx', 'created', 'discord010', 'student10@university.edu', 'sp_group_1', 1, '2025-06-16 21:03:01'),
        ('file_0023', 'both.docx', 'shared', 'discord011', 'student11@university.edu', 'sp_group_2', 2, '2025-06-22 19:26:09'),
        ('file_0024', 'we.docx', 'created', 'discord012', 'student12@university.edu', 'sp_group_3', 3, '2025-06-09 15:03:33'),
        ('file_0025', 'poor.docx', 'shared', 'discord001', 'student1@university.edu', 'sp_group_1', 1, '2025-06-22 00:49:10'),
        ('file_0026', 'story.docx', 'deleted', 'discord002', 'student2@university.edu', 'sp_group_2', 2, '2025-06-05 12:49:06'),
        ('file_0027', 'seek.docx', 'modified', 'discord003', 'student3@university.edu', 'sp_group_3', 3, '2025-06-10 04:01:58'),
        ('file_0028', 'star.docx', 'deleted', 'discord004', 'student4@university.edu', 'sp_group_1', 1, '2025-06-17 05:08:09'),
        ('file_0029', 'my.docx', 'created', 'discord005', 'student5@university.edu', 'sp_group_2', 2, '2025-06-25 02:54:44'),
        ('file_0030', 'maintain.docx', 'modified', 'discord006', 'student6@university.edu', 'sp_group_3', 3, '2025-06-21 19:05:54'),
        ('file_0031', 'protect.docx', 'modified', 'discord007', 'student7@university.edu', 'sp_group_1', 1, '2025-06-08 10:29:37'),
        ('file_0032', 'explain.docx', 'modified', 'discord008', 'student8@university.edu', 'sp_group_2', 2, '2025-06-07 00:28:23'),
        ('file_0033', 'admit.docx', 'modified', 'discord009', 'student9@university.edu', 'sp_group_3', 3, '2025-06-01 12:45:05'),
        ('file_0034', 'possible.docx', 'shared', 'discord010', 'student10@university.edu', 'sp_group_1', 1, '2025-05-31 13:38:54'),
        ('file_0035', 'consider.docx', 'created', 'discord011', 'student11@university.edu', 'sp_group_2', 2, '2025-06-15 21:32:04'),
        ('file_0036', 'energy.docx', 'created', 'discord012', 'student12@university.edu', 'sp_group_3', 3, '2025-06-06 08:29:04'),
        ('file_0037', 'feel.docx', 'deleted', 'discord001', 'student1@university.edu', 'sp_group_1', 1, '2025-05-27 01:55:23'),
        ('file_0038', 'site.docx', 'shared', 'discord002', 'student2@university.edu', 'sp_group_2', 2, '2025-06-21 18:21:05'),
        ('file_0039', 'myself.docx', 'created', 'discord003', 'student3@university.edu', 'sp_group_3', 3, '2025-06-16 13:23:27'),
        ('file_0040', 'town.docx', 'deleted', 'discord004', 'student4@university.edu', 'sp_group_1', 1, '2025-05-29 04:24:27'),
        ('file_0041', 'campaign.docx', 'deleted', 'discord005', 'student5@university.edu', 'sp_group_2', 2, '2025-06-14 10:37:21'),
        ('file_0042', 'quite.docx', 'created', 'discord006', 'student6@university.edu', 'sp_group_3', 3, '2025-06-08 23:53:19'),
        ('file_0043', 'by.docx', 'deleted', 'discord007', 'student7@university.edu', 'sp_group_1', 1, '2025-06-23 04:18:19'),
        ('file_0044', 'her.docx', 'modified', 'discord008', 'student8@university.edu', 'sp_group_2', 2, '2025-06-06 21:52:50'),
        ('file_0045', 'Republican.docx', 'deleted', 'discord009', 'student9@university.edu', 'sp_group_3', 3, '2025-06-22 07:17:58'),
        ('file_0046', 'recent.docx', 'shared', 'discord010', 'student10@university.edu', 'sp_group_1', 1, '2025-06-09 11:10:09'),
        ('file_0047', 'do.docx', 'created', 'discord011', 'student11@university.edu', 'sp_group_2', 2, '2025-06-07 10:11:58'),
        ('file_0048', 'attention.docx', 'shared', 'discord012', 'student12@university.edu', 'sp_group_3', 3, '2025-06-20 22:06:47'),
        ('file_0049', 'food.docx', 'deleted', 'discord001', 'student1@university.edu', 'sp_group_1', 1, '2025-06-11 15:45:50'),
        ('file_0050', 'east.docx', 'modified', 'discord002', 'student2@university.edu', 'sp_group_2', 2, '2025-06-13 21:13:36'),
        ('file_0051', 'production.docx', 'deleted', 'discord003', 'student3@university.edu', 'sp_group_3', 3, '2025-06-19 01:58:32'),
        ('file_0052', 'few.docx', 'modified', 'discord004', 'student4@university.edu', 'sp_group_1', 1, '2025-06-24 00:34:13'),
        ('file_0053', 'Congress.docx', 'modified', 'discord005', 'student5@university.edu', 'sp_group_2', 2, '2025-06-02 10:22:22'),
        ('file_0054', 'take.docx', 'modified', 'discord006', 'student6@university.edu', 'sp_group_3', 3, '2025-05-27 12:28:41'),
        ('file_0055', 'on.docx', 'created', 'discord007', 'student7@university.edu', 'sp_group_1', 1, '2025-06-20 06:36:48'),
        ('file_0056', 'generation.docx', 'deleted', 'discord008', 'student8@university.edu', 'sp_group_2', 2, '2025-06-13 13:59:31'),
        ('file_0057', 'pay.docx', 'shared', 'discord009', 'student9@university.edu', 'sp_group_3', 3, '2025-05-27 23:52:53'),
        ('file_0058', 'task.docx', 'created', 'discord010', 'student10@university.edu', 'sp_group_1', 1, '2025-06-02 01:40:31'),
        ('file_0059', 'under.docx', 'created', 'discord011', 'student11@university.edu', 'sp_group_2', 2, '2025-06-14 23:18:21'),
        ('file_0060', 'clear.docx', 'modified', 'discord012', 'student12@university.edu', 'sp_group_3', 3, '2025-06-04 08:22:00'),
        ('file_0061', 'Mrs.docx', 'modified', 'discord001', 'student1@university.edu', 'sp_group_1', 1, '2025-05-30 11:29:55'),
        ('file_0062', 'central.docx', 'created', 'discord002', 'student2@university.edu', 'sp_group_2', 2, '2025-06-05 19:00:56'),
        ('file_0063', 'station.docx', 'created', 'discord003', 'student3@university.edu', 'sp_group_3', 3, '2025-06-19 21:09:42'),
        ('file_0064', 'over.docx', 'shared', 'discord004', 'student4@university.edu', 'sp_group_1', 1, '2025-06-06 03:07:02'),
        ('file_0065', 'easy.docx', 'deleted', 'discord005', 'student5@university.edu', 'sp_group_2', 2, '2025-06-02 04:21:42'),
        ('file_0066', 'team.docx', 'modified', 'discord006', 'student6@university.edu', 'sp_group_3', 3, '2025-06-07 02:41:20'),
        ('file_0067', 'soon.docx', 'modified', 'discord007', 'student7@university.edu', 'sp_group_1', 1, '2025-06-14 10:31:12'),
        ('file_0068', 'head.docx', 'shared', 'discord008', 'student8@university.edu', 'sp_group_2', 2, '2025-06-25 07:22:25'),
        ('file_0069', 'perhaps.docx', 'deleted', 'discord009', 'student9@university.edu', 'sp_group_3', 3, '2025-06-02 04:50:31'),
        ('file_0070', 'professional.docx', 'shared', 'discord010', 'student10@university.edu', 'sp_group_1', 1, '2025-06-19 23:10:32'),
        ('file_0071', 'wear.docx', 'shared', 'discord011', 'student11@university.edu', 'sp_group_2', 2, '2025-06-01 00:15:58'),
        ('file_0072', 'power.docx', 'deleted', 'discord012', 'student12@university.edu', 'sp_group_3', 3, '2025-05-28 00:43:47'),
        ('file_0073', 'new.docx', 'created', 'discord001', 'student1@university.edu', 'sp_group_1', 1, '2025-06-24 16:57:27'),
        ('file_0074', 'her.docx', 'deleted', 'discord002', 'student2@university.edu', 'sp_group_2', 2, '2025-06-19 12:22:44'),
        ('file_0075', 'lose.docx', 'created', 'discord003', 'student3@university.edu', 'sp_group_3', 3, '2025-06-10 18:04:53'),
        ('file_0076', 'actually.docx', 'shared', 'discord004', 'student4@university.edu', 'sp_group_1', 1, '2025-06-06 10:49:30'),
        ('file_0077', 'one.docx', 'deleted', 'discord005', 'student5@university.edu', 'sp_group_2', 2, '2025-06-03 16:22:22'),
        ('file_0078', 'recent.docx', 'modified', 'discord006', 'student6@university.edu', 'sp_group_3', 3, '2025-06-19 18:47:12'),
        ('file_0079', 'table.docx', 'modified', 'discord007', 'student7@university.edu', 'sp_group_1', 1, '2025-06-19 02:43:39'),
        ('file_0080', 'office.docx', 'created', 'discord008', 'student8@university.edu', 'sp_group_2', 2, '2025-06-11 00:11:30');
    ");

    echo "✅ Tables created and data inserted!";

} catch (PDOException $e) {
    echo "❌ Error: " . htmlspecialchars($e->getMessage());
}
