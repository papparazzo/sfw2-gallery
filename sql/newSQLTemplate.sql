
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `{TABLE_PREFIX}_imagegalleries` (
    `Id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `PathId` int(10) unsigned NOT NULL,
    `UserId` int(10) unsigned NOT NULL,
    `CreationDate` date DEFAULT NULL,
    `Name` char(50) COLLATE utf8_unicode_ci NOT NULL,
    `Description` text COLLATE utf8_unicode_ci NOT NULL,
    `PreviewImage` varchar(25) COLLATE utf8_unicode_ci NOT NULL
    PRIMARY KEY (`Id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
