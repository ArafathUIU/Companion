-- ADMIN TABLE
CREATE TABLE admin (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    dob DATE,
    gender ENUM('male', 'female', 'other'),
    email VARCHAR(150) UNIQUE,
    password VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ANNOUNCEMENTS TABLE
CREATE TABLE announcements (
    announcement_id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_type ENUM('user', 'consultant', 'all'),
    title VARCHAR(255),
    message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ANONYMOUS COUNSELLING BOOKINGS
CREATE TABLE anonymous_counselling_bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    consultant_id INT,
    preferred_date DATE,
    preferred_time TIME,
    status ENUM('pending', 'confirmed', 'cancelled'),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (consultant_id) REFERENCES consultants(id)
);

-- AVATAR
CREATE TABLE avatar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255),
    gender ENUM('male', 'female', 'other')
);

-- CHAT MESSAGES
CREATE TABLE chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_user_id INT,
    receiver_user_id INT,
    message TEXT,
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_read BOOLEAN DEFAULT FALSE
);

-- CIRCLES
CREATE TABLE circles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255),
    description TEXT,
    lead_consultant_id INT,
    category VARCHAR(100),
    meeting_day VARCHAR(20),
    meeting_time TIME,
    max_members INT,
    status ENUM('active', 'inactive'),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- CIRCLE JOIN REQUEST
CREATE TABLE circle_join_request (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    circle_id INT,
    request_date DATE,
    status ENUM('pending', 'approved', 'rejected')
);

-- CIRCLE MEMBERS
CREATE TABLE circle_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    circle_id INT,
    user_id INT,
    status ENUM('active', 'left'),
    requested_at DATETIME
);

-- CIRCLE MESSAGES
CREATE TABLE circle_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    circle_id INT,
    user_id INT,
    message TEXT,
    sent_at DATETIME,
    sender_user_id INT,
    sender_consultant_id INT
);

-- COMMENTS
CREATE TABLE comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT,
    user_id INT,
    content TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- COMMUNITY POSTS
CREATE TABLE community_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    title VARCHAR(255),
    content TEXT,
    is_anonymous BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_approved BOOLEAN DEFAULT FALSE
);

-- CONSULTANTS
CREATE TABLE consultants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    dob DATE,
    gender ENUM('male', 'female', 'other'),
    email VARCHAR(150) UNIQUE,
    password VARCHAR(255),
    specialization TEXT,
    bio TEXT,
    office_address VARCHAR(255),
    session_charge DECIMAL(10,2),
    profile_picture VARCHAR(255),
    is_available BOOLEAN DEFAULT TRUE,
    video_consult_url TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    tags TEXT,
    latitude DOUBLE,
    longitude DOUBLE,
    status ENUM('active', 'inactive'),
    available_days TEXT,
    available_times TEXT
);

-- CONSULTANT AVAILABILITY
CREATE TABLE consultants_availability (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consultant_id INT,
    available_date DATE,
    start_time TIME,
    end_time TIME,
    is_booked BOOLEAN DEFAULT FALSE
);

-- CONSULTANT RECOMMENDATIONS
CREATE TABLE consultant_recommendations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    consultant_id INT,
    recommended_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- DISCUSSIONS
CREATE TABLE discussions (
    discussion_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255),
    status ENUM('open', 'closed'),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- DOCTOR JOURNALS
CREATE TABLE doctor_journals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consultant_id INT,
    user_id INT,
    title VARCHAR(255),
    content TEXT,
    tags TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- MENTAL EXERCISES
CREATE TABLE mental_exercises (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255),
    description TEXT,
    category VARCHAR(100),
    instructions TEXT,
    expected_input TEXT,
    correct_answer TEXT,
    duration INT,
    created_at DATETIME,
    updated_at DATETIME
);

-- MOOD JOURNAL
CREATE TABLE mood_journal (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    mood_emoji VARCHAR(20),
    mood_title VARCHAR(255),
    mood_note TEXT,
    entry_date DATE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- MOOD ENTRIES (optional duplicate structure)
CREATE TABLE mood_entries LIKE mood_journal;

-- MOOD LOGS
CREATE TABLE mood_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    mood VARCHAR(50),
    date DATE,
    created_at DATETIME
);

-- NOTIFICATIONS
CREATE TABLE notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(100),
    related_id INT,
    status ENUM('unread', 'read'),
    created_at DATETIME
);

-- PAYMENTS
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    booking_id INT,
    amount DECIMAL(10,2),
    payment_date DATETIME,
    payment_method VARCHAR(50),
    status ENUM('pending', 'completed', 'failed'),
    transaction_id VARCHAR(100),
    consultant_id INT,
    payment_details TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- POST LIKES
CREATE TABLE post_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT,
    user_id INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- SESSIONS
CREATE TABLE sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consultant_id INT,
    user_id INT,
    start_time DATETIME,
    status ENUM('active', 'ended'),
    agora_token TEXT,
    channel_name VARCHAR(100),
    created_at DATETIME,
    updated_at DATETIME
);

-- SESSION NOTES
CREATE TABLE session_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT,
    consultant_id INT,
    note_text TEXT,
    created_at DATETIME
);

-- SIGN UP QUESTIONS
CREATE TABLE sign_up_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_text TEXT,
    question_type VARCHAR(50),
    options_json JSON,
    created_at DATETIME
);

-- TASKS
CREATE TABLE tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT,
    user_id INT,
    consultant_id INT,
    task_description TEXT,
    due_date DATE,
    status ENUM('pending', 'done'),
    created_at DATETIME,
    updated_at DATETIME
);

-- USERS
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    dob DATE,
    gender ENUM('male', 'female', 'other'),
    email VARCHAR(150) UNIQUE,
    password VARCHAR(255),
    avatar VARCHAR(255),
    dashboard_theme VARCHAR(50),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    has_completed_questionnaire BOOLEAN DEFAULT FALSE,
    preferences TEXT,
    profile_avatar VARCHAR(255)
);

-- USER ACTIVITIES
CREATE TABLE user_activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    exercise_id INT,
    exercise_title VARCHAR(255),
    exercise_type VARCHAR(50),
    score FLOAT,
    time_taken INT,
    created_at DATETIME
);

-- USER EXERCISE COMPLETION
CREATE TABLE user_exercise_completion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    exercise_id INT,
    completed_at DATETIME
);

-- USER EXERCISE RECOMMENDATIONS
CREATE TABLE user_exercise_recommendations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    exercise_id INT,
    recommended_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- USER INTENT REASONS
CREATE TABLE user_intent_reasons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    reason TEXT,
    created_at DATETIME
);

-- USER LOGIN SESSIONS
CREATE TABLE user_login_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    session_token TEXT,
    ip_address VARCHAR(50),
    user_agent TEXT,
    created_at DATETIME,
    expires_at DATETIME
);

-- USER MOODS
CREATE TABLE user_moods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    mood VARCHAR(50),
    recorded_at DATETIME
);

-- USER MOOD LOG
CREATE TABLE user_mood_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    score FLOAT,
    created_at DATETIME
);

-- USER PROGRESS
CREATE TABLE user_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    score FLOAT,
    created_at DATETIME
);

-- USER SCORES
CREATE TABLE user_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    question_number INT,
    score FLOAT,
    created_at DATETIME
);

-- USER SESSIONS
CREATE TABLE user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    consultant_id INT,
    session_date DATE,
    start_time TIME,
    end_time TIME,
    status ENUM('scheduled', 'completed', 'cancelled'),
    video_link TEXT,
    created_at DATETIME
);

-- USER SIGNUP ANSWERS
CREATE TABLE user_signup_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    question_id INT,
    answer_text TEXT,
    created_at DATETIME
);

-- VIDEO SESSIONS
CREATE TABLE video_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consultant_id INT,
    consultant_name VARCHAR(100),
    user_id INT,
    room_link TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active', 'ended')
);
