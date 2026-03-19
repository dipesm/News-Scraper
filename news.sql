
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;


-- Dumping database structure for ecn
CREATE DATABASE IF NOT EXISTS `ecn` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;
USE `ecn`;

-- Dumping structure for table ecn.news_articles
CREATE TABLE IF NOT EXISTS `news_articles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(600) NOT NULL,
  `link` varchar(600) NOT NULL,
  `content` longtext DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `published_date` datetime DEFAULT NULL,
  `date_source` varchar(20) DEFAULT NULL COMMENT 'Where the date was extracted from: json_ld|meta|time_tag|css|url|relative|bs_converted|assumed_today',
  `date_confidence` enum('high','medium','low','none') DEFAULT 'none' COMMENT 'Confidence level of the extracted date',
  `image_url` text DEFAULT NULL,
  `local_image_path` text DEFAULT NULL,
  `source` varchar(150) DEFAULT NULL,
  `is_political` enum('Yes','No') DEFAULT 'No',
  `is_election_related` enum('Yes','No') DEFAULT 'No',
  `is_toxic` enum('Yes','No') DEFAULT 'No',
  `is_read` tinyint(1) DEFAULT 0 COMMENT '0 = unread (default), 1 = read',
  `content_hash` char(64) DEFAULT NULL,
  `language` varchar(5) DEFAULT 'ne',
  `scraped_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_link` (`link`),
  KEY `idx_category` (`category`),
  KEY `idx_published_date` (`published_date`),
  KEY `idx_source` (`source`),
  KEY `idx_scraped_date` (`scraped_at`),
  KEY `idx_is_read` (`is_read`),
  KEY `idx_read_scraped` (`is_read`,`scraped_at`),
  KEY `idx_scraped_at` (`scraped_at`),
  FULLTEXT KEY `ft_content` (`title`,`content`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.
-- Dumping structure for table ecn.news_sites
CREATE TABLE IF NOT EXISTS `news_sites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `base_url` varchar(255) NOT NULL,
  `article_selector` varchar(255) DEFAULT NULL,
  `title_selector` varchar(255) DEFAULT NULL,
  `content_selector` varchar(255) DEFAULT NULL,
  `engine_type` enum('wordpress','custom','hybrid') DEFAULT 'custom',
  `active` tinyint(1) DEFAULT 1,
  `is_priority` tinyint(1) DEFAULT 0 COMMENT '1 = always crawled on every run regardless of failures or scheduling',
  `success_count` int(11) DEFAULT 0,
  `failure_count` int(11) DEFAULT 0,
  `stability_score` float DEFAULT 0,
  `last_scraped` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_error` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `base_url` (`base_url`),
  KEY `idx_priority` (`is_priority`,`active`)
) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IF(@OLD_FOREIGN_KEY_CHECKS IS NULL, 1, @OLD_FOREIGN_KEY_CHECKS) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
