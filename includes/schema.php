<?php
/**
 * Canonical table definitions shared by the one-time schema upgrade.
 * Generated from install.php.
 */

    $tables = [];

    $tables['users'] = "CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(150) NOT NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        phone VARCHAR(40) NOT NULL,
        password VARCHAR(255) NOT NULL,
        google_id VARCHAR(100) NULL,
        auth_provider ENUM('password','google') NOT NULL DEFAULT 'password',
        password_reset_token VARCHAR(100) NULL,
        password_reset_expires DATETIME NULL,
        role ENUM('creator','admin') NOT NULL DEFAULT 'creator',
        status ENUM('active','suspended') NOT NULL DEFAULT 'active',
        qualified TINYINT(1) NOT NULL DEFAULT 0,
        assessment_status ENUM('not_taken','failed','passed') NOT NULL DEFAULT 'not_taken',
        assessment_score INT NULL,
        portfolio_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
        rejection_note TEXT NULL,
        country VARCHAR(80) NULL,
        social_handle VARCHAR(190) NULL,
        avatar_url VARCHAR(500) NULL,
        bio TEXT NULL,
        followers_count INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        qualified_at DATETIME NULL,
        INDEX (role), INDEX (qualified), INDEX (portfolio_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['settings'] = "CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['assessment_questions'] = "CREATE TABLE IF NOT EXISTS assessment_questions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        question TEXT NOT NULL,
        option_a VARCHAR(255) NOT NULL,
        option_b VARCHAR(255) NOT NULL,
        option_c VARCHAR(255) NOT NULL,
        option_d VARCHAR(255) NOT NULL,
        correct_option CHAR(1) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['assessments'] = "CREATE TABLE IF NOT EXISTS assessments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        score INT NOT NULL,
        total INT NOT NULL,
        percentage INT NOT NULL,
        passed TINYINT(1) NOT NULL DEFAULT 0,
        answers LONGTEXT NULL,
        taken_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        CONSTRAINT fk_assessment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['portfolio_links'] = "CREATE TABLE IF NOT EXISTS portfolio_links (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        platform VARCHAR(40) NOT NULL DEFAULT 'other',
        url VARCHAR(500) NOT NULL,
        note VARCHAR(255) NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        admin_note VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id), INDEX (status),
        CONSTRAINT fk_portfolio_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['footage'] = "CREATE TABLE IF NOT EXISTS footage (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        description TEXT NULL,
        category VARCHAR(100) NULL,
        campaign_id INT UNSIGNED NULL,
        file_name VARCHAR(255) NULL,
        file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        drive_url VARCHAR(500) NULL,
        downloads INT UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['footage_downloads'] = "CREATE TABLE IF NOT EXISTS footage_downloads (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        footage_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id), INDEX (footage_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['content_links'] = "CREATE TABLE IF NOT EXISTS content_links (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        platform VARCHAR(40) NOT NULL,
        url VARCHAR(500) NOT NULL,
        title VARCHAR(200) NULL,
        views BIGINT UNSIGNED NOT NULL DEFAULT 0,
        credited_views BIGINT UNSIGNED NOT NULL DEFAULT 0,
        earned DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        status ENUM('active','flagged') NOT NULL DEFAULT 'active',
        last_fetched_at DATETIME NULL,
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id), INDEX (status),
        CONSTRAINT fk_link_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['view_fetch_logs'] = "CREATE TABLE IF NOT EXISTS view_fetch_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        link_id INT UNSIGNED NOT NULL,
        views_before BIGINT UNSIGNED NOT NULL DEFAULT 0,
        views_after BIGINT UNSIGNED NOT NULL DEFAULT 0,
        views_gained BIGINT UNSIGNED NOT NULL DEFAULT 0,
        amount_credited DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        source VARCHAR(40) NOT NULL DEFAULT 'simulation',
        fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (link_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['community_messages'] = "CREATE TABLE IF NOT EXISTS community_messages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id), INDEX (created_at),
        CONSTRAINT fk_community_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['dm_threads'] = "CREATE TABLE IF NOT EXISTS dm_threads (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        is_support TINYINT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['dm_participants'] = "CREATE TABLE IF NOT EXISTS dm_participants (
        thread_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        last_read_at DATETIME NULL,
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (thread_id, user_id),
        INDEX (user_id),
        CONSTRAINT fk_dm_part_thread FOREIGN KEY (thread_id) REFERENCES dm_threads(id) ON DELETE CASCADE,
        CONSTRAINT fk_dm_part_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['dm_messages'] = "CREATE TABLE IF NOT EXISTS dm_messages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        thread_id INT UNSIGNED NOT NULL,
        sender_id INT UNSIGNED NOT NULL,
        message TEXT NOT NULL,
        is_bot TINYINT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (thread_id), INDEX (created_at),
        CONSTRAINT fk_dm_msg_thread FOREIGN KEY (thread_id) REFERENCES dm_threads(id) ON DELETE CASCADE,
        CONSTRAINT fk_dm_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['withdrawals'] = "CREATE TABLE IF NOT EXISTS withdrawals (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        status ENUM('pending','paid','rejected') NOT NULL DEFAULT 'pending',
        method VARCHAR(60) NULL,
        account_details VARCHAR(255) NULL,
        payment_provider VARCHAR(40) NULL,
        txn_reference VARCHAR(100) NULL,
        paid_to_number VARCHAR(30) NULL,
        admin_note VARCHAR(255) NULL,
        requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME NULL,
        INDEX (user_id), INDEX (status),
        CONSTRAINT fk_withdrawal_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['announcements'] = "CREATE TABLE IF NOT EXISTS announcements (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        body TEXT NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['campaigns'] = "CREATE TABLE IF NOT EXISTS campaigns (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        description TEXT NULL,
        banner_url VARCHAR(500) NULL,
        owner_name VARCHAR(200) NULL,
        owner_avatar_url VARCHAR(500) NULL,
        owner_verified TINYINT(1) NOT NULL DEFAULT 1,
        tag VARCHAR(100) NULL,
        total_budget DECIMAL(12,2) NOT NULL DEFAULT 0,
        paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        creator_cap DECIMAL(12,2) NULL,
        currency VARCHAR(10) NOT NULL DEFAULT 'K',
        platforms TEXT NULL,
        reference_links TEXT NULL,
        requirements TEXT NULL,
        requirements_url VARCHAR(500) NULL,
        styled_videos_url VARCHAR(500) NULL,
        camp_status ENUM('accepting','closed') NOT NULL DEFAULT 'accepting',
        status ENUM('active','ended') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['ann_posts'] = "CREATE TABLE IF NOT EXISTS ann_posts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        author_id INT UNSIGNED NOT NULL,
        body TEXT NOT NULL,
        media_url VARCHAR(500) NULL,
        pinned TINYINT(1) NOT NULL DEFAULT 0,
        parent_id INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        like_count INT UNSIGNED NOT NULL DEFAULT 0,
        comment_count INT UNSIGNED NOT NULL DEFAULT 0,
        view_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        INDEX (author_id), INDEX (pinned), INDEX (parent_id), INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['ann_likes'] = "CREATE TABLE IF NOT EXISTS ann_likes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq (post_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['ann_views'] = "CREATE TABLE IF NOT EXISTS ann_views (
        post_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (post_id, user_id), INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['campaign_joins'] = "CREATE TABLE IF NOT EXISTS campaign_joins (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq (campaign_id, user_id),
        INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['campaign_submissions'] = "CREATE TABLE IF NOT EXISTS campaign_submissions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        platform VARCHAR(40) NOT NULL,
        url VARCHAR(500) NOT NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        views BIGINT UNSIGNED NOT NULL DEFAULT 0,
        earned DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        credited_views BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_fetched_at DATETIME NULL,
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (campaign_id), INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['courses'] = "CREATE TABLE IF NOT EXISTS courses (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        description TEXT NULL,
        thumbnail_url VARCHAR(500) NULL,
        published TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        chapters TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['course_progress'] = "CREATE TABLE IF NOT EXISTS course_progress (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        course_id INT UNSIGNED NOT NULL,
        completed_lessons TEXT NULL,
        last_lesson_id INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY uniq (user_id, course_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['ann_comments'] = "CREATE TABLE IF NOT EXISTS ann_comments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        body TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (post_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['social_pages'] = "CREATE TABLE IF NOT EXISTS social_pages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        platform VARCHAR(40) NOT NULL,
        url VARCHAR(500) NOT NULL,
        page_name VARCHAR(200) NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        admin_note VARCHAR(255) NULL,
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id), INDEX (status),
        CONSTRAINT fk_social_page_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['feedback'] = "CREATE TABLE IF NOT EXISTS feedback (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        subject VARCHAR(160) NOT NULL,
        message TEXT NOT NULL,
        status ENUM('open','resolved') NOT NULL DEFAULT 'open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME NULL,
        INDEX (status), INDEX (user_id),
        CONSTRAINT fk_feedback_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['notifications'] = "CREATE TABLE IF NOT EXISTS notifications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        type VARCHAR(40) NOT NULL DEFAULT 'system',
        title VARCHAR(190) NOT NULL,
        body VARCHAR(255) NULL,
        url VARCHAR(500) NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX user_unread (user_id, is_read, created_at),
        CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

return $tables;
