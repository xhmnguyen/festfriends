SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS report;
DROP TABLE IF EXISTS concert_rsvp;
DROP TABLE IF EXISTS gallery_image;
DROP TABLE IF EXISTS performance_interest;
DROP TABLE IF EXISTS general_post_vote;
DROP TABLE IF EXISTS general_post;
DROP TABLE IF EXISTS housing_option_join;
DROP TABLE IF EXISTS housing_option;
DROP TABLE IF EXISTS transport_option_join;
DROP TABLE IF EXISTS transport_option;
DROP TABLE IF EXISTS performance_slot;
DROP TABLE IF EXISTS concert_stage;
DROP TABLE IF EXISTS user_budget;
DROP TABLE IF EXISTS group_concert;
DROP TABLE IF EXISTS group_member;
DROP TABLE IF EXISTS user_group;
DROP TABLE IF EXISTS `user`;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `user` (
    user_id INT NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(254) NOT NULL UNIQUE,
    image VARCHAR(500) DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (user_id),

    CONSTRAINT chk_user_username_length
        CHECK (CHAR_LENGTH(username) BETWEEN 3 AND 50),
    CONSTRAINT chk_user_full_name_length
        CHECK (CHAR_LENGTH(full_name) BETWEEN 1 AND 100),
    CONSTRAINT chk_user_email_length
        CHECK (CHAR_LENGTH(email) BETWEEN 5 AND 254),
    CONSTRAINT chk_user_password_length
        CHECK (CHAR_LENGTH(password) BETWEEN 8 AND 255)
);

CREATE TABLE user_group (
    group_id INT NOT NULL AUTO_INCREMENT,
    owner_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(1000) DEFAULT NULL,
    image VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (group_id),

    CONSTRAINT fk_user_group_owner
        FOREIGN KEY (owner_id) REFERENCES `user`(user_id)
        ON DELETE CASCADE,
    CONSTRAINT chk_user_group_name_length
        CHECK (CHAR_LENGTH(name) BETWEEN 1 AND 100)
);

CREATE TABLE group_member (
    group_member_id INT NOT NULL AUTO_INCREMENT,
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (group_member_id),
    UNIQUE KEY uq_group_member (group_id, user_id),

    CONSTRAINT fk_group_member_group
        FOREIGN KEY (group_id) REFERENCES user_group(group_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_group_member_user
        FOREIGN KEY (user_id) REFERENCES `user`(user_id)
        ON DELETE CASCADE
);

CREATE TABLE group_concert (
    group_concert_id INT NOT NULL AUTO_INCREMENT,
    group_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    location VARCHAR(255) DEFAULT NULL,
    start_date DATE NOT NULL,
    end_date DATE DEFAULT NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 0,
    image VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (group_concert_id),

    CONSTRAINT fk_group_concert_group
        FOREIGN KEY (group_id) REFERENCES user_group(group_id)
        ON DELETE CASCADE,
    CONSTRAINT chk_group_concert_name_length
        CHECK (CHAR_LENGTH(name) BETWEEN 1 AND 150),
    CONSTRAINT chk_group_concert_dates
        CHECK (end_date IS NULL OR end_date >= start_date),
    CONSTRAINT chk_group_concert_all_day
        CHECK (all_day IN (0, 1))
);

CREATE TABLE concert_rsvp (
    concert_rsvp_id INT NOT NULL AUTO_INCREMENT,
    group_concert_id INT NOT NULL,
    user_id INT NOT NULL,
    status ENUM('going', 'not_going') NOT NULL DEFAULT 'not_going',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (concert_rsvp_id),
    UNIQUE KEY uq_concert_rsvp (group_concert_id, user_id),

    CONSTRAINT fk_concert_rsvp_concert
        FOREIGN KEY (group_concert_id) REFERENCES group_concert(group_concert_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_concert_rsvp_user
        FOREIGN KEY (user_id) REFERENCES `user`(user_id)
        ON DELETE CASCADE
);

CREATE TABLE general_post (
    post_id INT NOT NULL AUTO_INCREMENT,
    group_concert_id INT NOT NULL,
    user_id INT NOT NULL,
    title VARCHAR(150) DEFAULT NULL,
    content VARCHAR(2000) NOT NULL,
    image VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (post_id),

    CONSTRAINT fk_general_post_concert
        FOREIGN KEY (group_concert_id) REFERENCES group_concert(group_concert_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_general_post_user
        FOREIGN KEY (user_id) REFERENCES `user`(user_id)
        ON DELETE CASCADE,
    CONSTRAINT chk_general_post_content_length
        CHECK (CHAR_LENGTH(content) BETWEEN 1 AND 2000)
);

CREATE TABLE general_post_vote (
    post_vote_id INT NOT NULL AUTO_INCREMENT,
    post_id INT NOT NULL,
    user_id INT NOT NULL,
    vote TINYINT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (post_vote_id),
    UNIQUE KEY uq_general_post_vote (post_id, user_id),

    CONSTRAINT fk_general_post_vote_post
        FOREIGN KEY (post_id) REFERENCES general_post(post_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_general_post_vote_user
        FOREIGN KEY (user_id) REFERENCES `user`(user_id)
        ON DELETE CASCADE,
    CONSTRAINT chk_general_post_vote
        CHECK (vote IN (-1, 1))
);

CREATE TABLE housing_option (
    housing_id INT NOT NULL AUTO_INCREMENT,
    group_concert_id INT NOT NULL,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    arrival_date DATE NOT NULL,
    departure_date DATE NOT NULL,
    include_time TINYINT(1) NOT NULL DEFAULT 0,
    arrival_time TIME DEFAULT NULL,
    departure_time TIME DEFAULT NULL,
    total_cost DECIMAL(7,2) DEFAULT NULL,
    limited_spots TINYINT(1) NOT NULL DEFAULT 0,
    max_people INT DEFAULT NULL,
    image VARCHAR(500) DEFAULT NULL,
    link VARCHAR(500) DEFAULT NULL,
    notes VARCHAR(2000) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (housing_id),

    CONSTRAINT fk_housing_option_concert
        FOREIGN KEY (group_concert_id) REFERENCES group_concert(group_concert_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_housing_option_user
        FOREIGN KEY (user_id) REFERENCES `user`(user_id)
        ON DELETE CASCADE,
    CONSTRAINT chk_housing_dates
        CHECK (departure_date >= arrival_date),
    CONSTRAINT chk_housing_total_cost
        CHECK (total_cost IS NULL OR total_cost BETWEEN 0.00 AND 99999.99),
    CONSTRAINT chk_housing_include_time
        CHECK (include_time IN (0, 1)),
    CONSTRAINT chk_housing_limited_spots
        CHECK (limited_spots IN (0, 1)),
    CONSTRAINT chk_housing_max_people
        CHECK (max_people IS NULL OR max_people BETWEEN 2 AND 20)
);

CREATE TABLE housing_option_join (
    housing_option_join_id INT NOT NULL AUTO_INCREMENT,
    housing_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (housing_option_join_id),
    UNIQUE KEY uq_housing_join (housing_id, user_id),

    CONSTRAINT fk_housing_join_housing
        FOREIGN KEY (housing_id) REFERENCES housing_option(housing_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_housing_join_user
        FOREIGN KEY (user_id) REFERENCES `user`(user_id)
        ON DELETE CASCADE
);

CREATE TABLE transport_option (
    transport_id INT NOT NULL AUTO_INCREMENT,
    group_concert_id INT NOT NULL,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    arrival_date DATE NOT NULL,
    departure_date DATE NOT NULL,
    include_time TINYINT(1) NOT NULL DEFAULT 0,
    arrival_time TIME DEFAULT NULL,
    departure_time TIME DEFAULT NULL,
    total_cost DECIMAL(7,2) DEFAULT NULL,
    limited_spots TINYINT(1) NOT NULL DEFAULT 0,
    max_people INT DEFAULT NULL,
    link VARCHAR(500) DEFAULT NULL,
    notes VARCHAR(2000) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (transport_id),

    CONSTRAINT fk_transport_option_concert
        FOREIGN KEY (group_concert_id) REFERENCES group_concert(group_concert_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_transport_option_user
        FOREIGN KEY (user_id) REFERENCES `user`(user_id)
        ON DELETE CASCADE,
    CONSTRAINT chk_transport_dates
        CHECK (departure_date >= arrival_date),
    CONSTRAINT chk_transport_total_cost
        CHECK (total_cost IS NULL OR total_cost BETWEEN 0.00 AND 99999.99),
    CONSTRAINT chk_transport_include_time
        CHECK (include_time IN (0, 1)),
    CONSTRAINT chk_transport_limited_spots
        CHECK (limited_spots IN (0, 1)),
    CONSTRAINT chk_transport_max_people
        CHECK (max_people IS NULL OR max_people BETWEEN 2 AND 20)
);

CREATE TABLE transport_option_join (
    transport_option_join_id INT NOT NULL AUTO_INCREMENT,
    transport_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (transport_option_join_id),
    UNIQUE KEY uq_transport_join (transport_id, user_id),

    CONSTRAINT fk_transport_join_transport
        FOREIGN KEY (transport_id) REFERENCES transport_option(transport_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_transport_join_user
        FOREIGN KEY (user_id) REFERENCES `user`(user_id)
        ON DELETE CASCADE
);

CREATE TABLE concert_stage (
    stage_id INT NOT NULL AUTO_INCREMENT,
    group_concert_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(20) NOT NULL DEFAULT '#7c3aed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (stage_id),

    CONSTRAINT fk_concert_stage_concert
        FOREIGN KEY (group_concert_id) REFERENCES group_concert(group_concert_id)
        ON DELETE CASCADE,
    CONSTRAINT chk_concert_stage_name_length
        CHECK (CHAR_LENGTH(name) BETWEEN 1 AND 100)
);

CREATE TABLE performance_slot (
    performance_id INT NOT NULL AUTO_INCREMENT,
    group_concert_id INT NOT NULL,
    user_id INT NOT NULL,
    artist_name VARCHAR(150) NOT NULL,
    stage_id INT DEFAULT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (performance_id),

    CONSTRAINT fk_performance_slot_concert
        FOREIGN KEY (group_concert_id) REFERENCES group_concert(group_concert_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_performance_slot_user
        FOREIGN KEY (user_id) REFERENCES `user`(user_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_performance_slot_stage
        FOREIGN KEY (stage_id) REFERENCES concert_stage(stage_id)
        ON DELETE SET NULL,
    CONSTRAINT chk_performance_slot_time
        CHECK (end_time > start_time)
);

CREATE TABLE performance_interest (
    performance_interest_id INT NOT NULL AUTO_INCREMENT,
    performance_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (performance_interest_id),
    UNIQUE KEY uq_performance_interest (performance_id, user_id),

    CONSTRAINT fk_performance_interest_performance
        FOREIGN KEY (performance_id) REFERENCES performance_slot(performance_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_performance_interest_user
        FOREIGN KEY (user_id) REFERENCES `user`(user_id)
        ON DELETE CASCADE
);

CREATE TABLE gallery_image (
    image_id INT NOT NULL AUTO_INCREMENT,
    group_concert_id INT NOT NULL,
    user_id INT NOT NULL,
    image VARCHAR(500) NOT NULL,
    caption VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (image_id),

    CONSTRAINT fk_gallery_image_concert
        FOREIGN KEY (group_concert_id) REFERENCES group_concert(group_concert_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_gallery_image_user
        FOREIGN KEY (user_id) REFERENCES `user`(user_id)
        ON DELETE CASCADE
);

CREATE TABLE user_budget (
    user_budget_id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    group_concert_id INT NOT NULL,
    housing_budget DECIMAL(7,2) NOT NULL DEFAULT 0.00,
    transportation_budget DECIMAL(7,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (user_budget_id),
    UNIQUE KEY uq_user_budget (user_id, group_concert_id),

    CONSTRAINT fk_user_budget_user
        FOREIGN KEY (user_id) REFERENCES `user`(user_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_user_budget_concert
        FOREIGN KEY (group_concert_id) REFERENCES group_concert(group_concert_id)
        ON DELETE CASCADE,
    CONSTRAINT chk_user_budget_housing
        CHECK (housing_budget BETWEEN 0.00 AND 99999.99),
    CONSTRAINT chk_user_budget_transportation
        CHECK (transportation_budget BETWEEN 0.00 AND 99999.99)
);

CREATE TABLE report (
    report_id INT NOT NULL AUTO_INCREMENT,
    reporter_id INT NOT NULL,
    target_type ENUM('group', 'concert', 'general_post', 'housing', 'transport', 'user') NOT NULL,
    target_id INT NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    status ENUM('unresolved', 'resolved') NOT NULL DEFAULT 'unresolved',
    resolved_by INT DEFAULT NULL,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    action_taken VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (report_id),

    CONSTRAINT fk_report_reporter
        FOREIGN KEY (reporter_id) REFERENCES `user`(user_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_report_resolver
        FOREIGN KEY (resolved_by) REFERENCES `user`(user_id)
        ON DELETE SET NULL,
    CONSTRAINT chk_report_reason_length
        CHECK (CHAR_LENGTH(reason) BETWEEN 1 AND 1000)
);