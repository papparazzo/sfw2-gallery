<?php

/**
 *  SFW2 - SimpleFrameWork
 *
 *  Copyright (C) 2018  Stefan Paproth
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program. If not, see <http://www.gnu.org/licenses/agpl.txt>.
 *
 */

namespace SFW2\Gallery\Controller;

use SFW2\Routing\AbstractController;
use SFW2\Routing\Result\Content;
use SFW2\Routing\Resolver\ResolverException;

use SFW2\Controllers\Widget\Obfuscator\EMail;
use SFW2\Authority\User;

use SFW2\Core\Database;
use SFW2\Core\Config;

use SFW2\Validator\Ruleset;
use SFW2\Validator\Validator;
use SFW2\Validator\Validators\IsNotEmpty;

use SFW2\Controllers\Controller\Helper\DateTimeHelperTrait;
use SFW2\Controllers\Controller\Helper\ImageHelperTrait;
use SFW2\Controllers\Controller\Helper\EMailHelperTrait;

use SFW2\Gallery\Helper\GalleryHelperTrait;
use SFW2\Gallery\GalleryException;

class Gallery extends AbstractController {

    use DateTimeHelperTrait;
    use ImageHelperTrait;
    use GalleryHelperTrait;
    use EMailHelperTrait;

    protected Database $database;
    protected Config $config;
    protected User $user;
    protected ?string $title;

    const SUMMERIES_PER_PAGE = 3;
    const PREVIEW_FILE = 'preview.png';
    const DIMENSIONS = 130;

    public function __construct(int $pathId, Database $database, Config $config, User $user, string $title = null) {
        parent::__construct($pathId);
        $this->database = $database;
        $this->user = $user;
        $this->config = $config;
        $this->title = $title;
    }

    public function index(bool $all = false) : Content {
        unset($all);
        $content = new Content('SFW2\\Gallery\\Summary');

        $content->assign('caption',          $this->title ?? 'Galerieübersicht');
        $content->assign('title',            $this->title);
        $content->assign('modificationDate', $this->getLastModificatonDate());
        $content->assign('webmaster',        (string)(new EMail($this->config->getVal('project', 'eMailWebMaster'))));

        $content->appendJSFile('Gallery.handlebars.js');
        return $content;
    }

    protected function getLastModificatonDate() {
        $stmt =
            "SELECT `imagegalleries`.`CreationDate` " .
            "FROM `{TABLE_PREFIX}_imagegalleries` AS `imagegalleries` " .
            "WHERE `imagegalleries`.`PathId` = '%s' " .
            "ORDER BY `imagegalleries`.`CreationDate`";

        return $this->database->selectSingle($stmt, [$this->pathId]);
    }

    /**
     * @param bool $all
     * @return \SFW2\Routing\Result\Content
     * @noinspection PhpMissingParentCallCommonInspection
     */
    public function read(bool $all = false): Content {
        unset($all);
        $count = (int)filter_input(INPUT_GET, 'count', FILTER_VALIDATE_INT);
        $start = (int)filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT);

        $count = $count ? $count : self::SUMMERIES_PER_PAGE;

        $stmt =
            "SELECT `imagegalleries`.`Id`, `imagegalleries`.`Title`, `imagegalleries`.`Description`, `imagegalleries`.`CreationDate`, " .
            "`user`.`Email`, CONCAT(`user`.`FirstName`, ' ', `user`.`LastName`) AS `Creator`, " .
            "IF(`imagegalleries`.`UserId` = '%s', '1', '0') AS `OwnEntry` " .
            "FROM `{TABLE_PREFIX}_imagegalleries` AS `imagegalleries` " .
            "LEFT JOIN `{TABLE_PREFIX}_user` AS `user` " .
            "ON `user`.`Id` = `imagegalleries`.`UserId` " .
            "WHERE `imagegalleries`.`PathId` = '%s' " .
            "ORDER BY `imagegalleries`.`Id` DESC " .
            "LIMIT %s, %s " ;

        $rows = $this->database->select($stmt, [$this->user->getUserId(), $this->pathId, $start, $count]);
        $cnt = $this->database->selectCount('{TABLE_PREFIX}_imagegalleries', "WHERE `PathId` = '%s'", [$this->pathId]);

        $content = new Content('Gallery');
        $entries = [];

        foreach($rows as $row) {
            $cd = $this->getDate($row['CreationDate']);
            $entry = [];
            $entry['id'               ] = $row['Id'];
            $entry['date'             ] = $cd;
            $entry['title'            ] = $row['Title'];
            $entry['link'             ] = '?do=showGallery&id=' . $row['Id'];
            $entry['description'      ] = $row['Description'];
            $entry['ownEntry'         ] = (bool)$row['OwnEntry'];
            $entry['previewImage'     ] = $this->getPreviewImage($row['Id']);
            $entry['creator'          ] = (string)$this->getEMail($row["Email"], $row['Creator'], 'Galerie ' . $row['Title'] . ' (' . $cd . ")");
            $entries[] = $entry;
        }

        $content->assign('offset', $start + $count);
        $content->assign('hasNext', $start + $count < $cnt);
        $content->assign('entries', $entries);
        return $content;
    }

    /**
     * @throws ResolverException
     */
    public function showGallery(): Content {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if($id == false) {
            throw new ResolverException("no gallery fetched!", ResolverException::INVALID_DATA_GIVEN);
        }
        $page = (int)filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);

        $stmt =
            "SELECT `imagegalleries`.`Title`, `imagegalleries`.`CreationDate`, `imagegalleries`.`Description`,  " .
            "`user`.`Email`,  CONCAT(`user`.`FirstName`, ' ', `user`.`LastName`) AS `Creator` " .
            "FROM `{TABLE_PREFIX}_imagegalleries` AS `imagegalleries` " .
            "LEFT JOIN `{TABLE_PREFIX}_user` AS `user` ON `user`.`Id` = `imagegalleries`.`UserId` " .
            "WHERE `imagegalleries`.`Id` = '%s' ";

        $row = $this->database->selectRow($stmt, [$id]);

        if(empty($row)) {
            throw new ResolverException("no gallery found for id <$id>!", ResolverException::INVALID_DATA_GIVEN);
        }

        $path = $this->getGalleryPath($id);
        $pics = [];
        if(is_dir($path . '/thumb/')) {
            $pics = $this->getImageList($path);
        }

        $cd = $this->getDate($row['CreationDate']);
        $content = new Content('SFW2\\Gallery\\Gallery');
        $content->assign('caption',           $row['Title']);
        $content->assign('id',                (int)$id);
        $content->assign('mailaddr',          (string)$this->getEMail($row["Email"], $row['Creator'], 'Galerie ' . $row['Title'] . ' (' . $cd . ")"));
        $content->assign('creationDate',      $cd);
        $content->assign('description',       $row['Description']);
        $content->assign('page',              (int)$page);
        $content->assign('pics',              $pics);
        $content->assign('editable',          true);
        $content->assign('previewImage',      $this->getPreviewImage($id));
        $content->assign('maxFileUploads',    ini_get('max_file_uploads'));
        $content->appendCSSFile('lightbox.min.css');
        $content->appendJSFile('lightbox.min.js');
        return $content;
    }

    /**
     * @return \SFW2\Routing\Result\Content
     * @noinspection PhpMissingParentCallCommonInspection
     */
    public function create(): Content {
        $content = new Content('Gallery');

        $rulset = new Ruleset();
        $rulset->addNewRules('caption', new IsNotEmpty());
        $rulset->addNewRules('description', new IsNotEmpty());

        $validator = new Validator($rulset);
        $values = [];

        $error = $validator->validate($_POST, $values);
        $content->assignArray($values);

        if(!$error) {
            $content->setError(true);
            return $content;
        }

        $stmt =
            "INSERT INTO `{TABLE_PREFIX}_imagegalleries` " .
            "SET `PathId` = '%s', `UserId` = '%s', `CreationDate` = NOW(), `Title` = '%s', `Description` = '%s' ";

        $id = $this->database->insert(
            $stmt,
            [
                $this->pathId,
                $this->user->getUserId(),
                $values['caption']['value'],
                $values['description']['value'],
            ]
        );

        $cd = $this->getShortDate();
        $content = new Content('Gallery');
        $content->assign('id',           ['value' => $id]);
        $content->assign('date',         ['value' => $cd]);
        $content->assign('title',        ['value' => $values['caption']['value']]);
        $content->assign('description',  ['value' => $values['description']['value']]);
        $content->assign('link',         ['value' => '?do=showGallery&id=' . $id]);
        $content->assign('ownEntry',     ['value' => true]);
        $content->assign('previewImage', ['value' => $this->getPreviewImage()]);
        $content->assign('creator',  ['value' => $this->getEMailByUser($this->user, 'Galerie ' . $values['caption']['value'] . ' (' . $cd . ")")]);

        $content->dataWereModified();
        return $content;
    }

    /**
     * @throws \SFW2\Gallery\GalleryException
     * @throws ResolverException
     * @noinspection PhpMissingParentCallCommonInspection
     */
    public function delete(bool $all = false): Content {
        $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_STRING);
        if($id == false) {
            throw new ResolverException("no gallery fetched!", ResolverException::INVALID_DATA_GIVEN);
        }

        $tokens = explode('__', $id);
        if(count($tokens) == 1) {
            $this->deletGallery($tokens[0], $all);
        } else {
            $this->deleteImage(array_shift($tokens), implode('__', $tokens), $all);
        }
        return new Content();
    }

    /**
     * @throws \SFW2\Gallery\GalleryException
     * @throws ResolverException
     */
    public function changePreview(): Content {
        $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_STRING);
        $p = strpos($id, '__');
        if($p === false) {
            throw new ResolverException("no gallery fetched!", ResolverException::INVALID_DATA_GIVEN);
        }

        $file = substr($id, $p + 2);
        $galleryId = substr($id, 0, $p);

        $path = $this->getGalleryPath($galleryId);
        $this->generatePreview($file, self::DIMENSIONS, $path . '/high/', $path);
        return new Content();
    }

    /**
     * @throws ResolverException
     * @throws \Exception
     */
    public function rotateImage(): Content {
        $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_STRING);
        $p = strpos($id, '__');
        if($p === false) {
            throw new ResolverException("no gallery fetched!", ResolverException::INVALID_DATA_GIVEN);
        }

        $file = substr($id, $p + 2);
        $galleryId = substr($id, 0, $p);

        $path = $this->getGalleryPath($galleryId);

        $this->rotate($path . 'thumb/' . $file);
        $this->rotate($path . 'high/' . $file);
        return new Content();
    }

    /**
     * @throws \Exception
     */
    protected function rotate(string $file) {
        list(, , $srcTyp) = getimagesize($file);
        switch ($srcTyp) {
            case IMAGETYPE_JPEG:
                $old = imagecreatefromjpeg($file);
                break;

            case IMAGETYPE_PNG:
                $old = imagecreatefrompng($file);
                break;
        }

        $new = imagerotate($old, 180, 0);

        imagepng($new, $file);
        imagedestroy($new);
        imagedestroy($old);
    }

    protected function getGalleryPath(int $galleryId) : string {
        return 'img' . DIRECTORY_SEPARATOR . $this->pathId . DIRECTORY_SEPARATOR . $galleryId . DIRECTORY_SEPARATOR;
    }

    protected function getPreviewImage(int $id = 0) : string {
        if($id == 0) {
            return "/img/layout/empty.png";
        }
        $preview = $this->getGalleryPath($id) . self::PREVIEW_FILE;

        if(!is_file($preview)) {
            return "/img/layout/empty.png";
        }
        return '/' . $preview;
    }

    /**
     * @throws ResolverException
     */
    protected function deletGallery(int $galleryId, bool $all = false): void {
        $stmt = "DELETE FROM `{TABLE_PREFIX}_imagegalleries` WHERE `Id` = '%s' AND `PathId` = '%s'";

        if(!$all) {
            $stmt .= "AND `UserId` = '" . $this->database->escape($this->user->getUserId()) . "'";
        }

        if(!$this->database->delete($stmt, [$galleryId, $this->pathId])) {
            throw new ResolverException("no entry found", ResolverException::NO_PERMISSION);
        }

        $path = $this->getGalleryPath($galleryId);
        if(!is_dir($path . '/thumb/')) {
            if(is_dir($path)) {
                rmdir($path);
            }
            return;
        }

        $dir = dir($path . '/thumb/');

        while(false !== ($entry = $dir->read())) {
            if($entry == '.' || $entry == '..') {
                continue;
            }

            unlink($path . '/thumb/' . $entry);
            unlink($path . '/high/' . $entry);
        }
        $dir->close();
        if(is_file($path . '/' . self::PREVIEW_FILE)) {
            unlink($path . '/' . self::PREVIEW_FILE);
        }

        rmdir($path . '/thumb/');
        rmdir($path . '/high/');
        rmdir($path);
    }

    /**
     * @throws \SFW2\Gallery\GalleryException
     */
    protected function deleteImage(int $galleryId, string $fileName, bool $all = false): void {
        $stmt =
            "SELECT `imagegalleries`.`Id` " .
            "FROM `{TABLE_PREFIX}_imagegalleries` AS `imagegalleries` " .
            "WHERE `imagegalleries`.`Id` = '%s' AND `imagegalleries`.`PathId` = '%s' ";

        if(!$all) {
            $stmt .= "AND `UserId` = '" . $this->database->escape($this->user->getUserId()) . "'";
        }

        $row = $this->database->selectRow($stmt, [$galleryId, $this->pathId]);

        if(empty($row)) {
            throw new GalleryException('no valid gallery fetched!', GalleryException::NO_GALLERY_FETCHED);
        }

        $path = $this->getGalleryPath($galleryId);

        unlink($path . 'thumb/' . $fileName);
        unlink($path . 'high/' . $fileName);
    }

    /**
     * @throws \SFW2\Gallery\GalleryException
     */
    public function addImage(): Content {
        $galleryId = filter_input(INPUT_POST, 'gallery', FILTER_SANITIZE_STRING);

        $folder = $this->getGalleryPath($galleryId);

        $filename = $this->addFile($folder, self::DIMENSIONS);

        $highFolder = $folder . DIRECTORY_SEPARATOR . 'high' . DIRECTORY_SEPARATOR;

        if(!is_file($folder . self::PREVIEW_FILE)) {
            $this->generatePreview($filename, self::DIMENSIONS, $highFolder, $folder);
        }

        return new Content();
    }

    /**
     * @throws \SFW2\Gallery\GalleryException
     * @throws \Exception
     */
    protected function generatePreview(string $file, int $dimensions, string $src, string $des): void {
        $srcFile = $src . '/' . $file;

        if(!is_file($srcFile)) {
            throw new GalleryException("file <$srcFile> is not a valid image!", GalleryException::INVALID_IMAGE);
        }

        list($oldW, $oldH, $srcTyp) = getimagesize($srcFile);


        if($oldH > $oldW) {
            $newW = $dimensions;
            $newH = ($oldH / $oldW) * $dimensions;
            $limD = $oldW;
        } else {
            $newH = $dimensions;
            $newW = ($oldW / $oldH) * $dimensions;
            $limD = $oldH;
        }

        $new = imagecreatetruecolor($dimensions, $dimensions);

        switch ($srcTyp) {
            case IMAGETYPE_JPEG:
                $old = imagecreatefromjpeg($srcFile);
                break;

            case IMAGETYPE_PNG:
                $old = imagecreatefrompng($srcFile);
                break;
        }

        imagecopyresampled($new, $old, 0, 0, ($newW - $dimensions) / 2, ($newH - $dimensions) / 2, $dimensions, $dimensions, $limD, $limD);
        imagepng($new, $des . '/' . self::PREVIEW_FILE);
        imagedestroy($new);
        imagedestroy($old);
    }

    protected function getImageList(string $path) : array{
        $dir = dir($path . '/thumb/');
        $pics = [];

        while(false !== ($entry = $dir->read())) {
            if($entry == '.' || $entry == '..') {
                continue;
            }
            $fi = pathinfo($path . '/thumb/' . $entry);
            if(strtolower($fi['extension']) != 'jpg' && strtolower($fi['extension']) != 'png') {
                continue;
            }
            $pic = [];
            $pic['lnk'] = '/' . $path . '/high/' . $entry;
            $pic['ttp'] = $entry;
            $pic['src'] = '/' . $path . '/thumb/' . $entry;
            $pics[] = $pic;
        }

        $dir->close();
        rsort($pics);
        return $pics;
    }
}
