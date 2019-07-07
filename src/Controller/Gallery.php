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
use SFW2\Controllers\Widget\Obfuscator\EMail;
use SFW2\Authority\User;
use SFW2\Core\Database;
use SFW2\Core\Config;

use SFW2\Controllers\Controller\Helper\DateTimeHelperTrait;
use SFW2\Controllers\Controller\Helper\ImageHelperTrait;
use SFW2\Controllers\Controller\Helper\EMailHelperTrait;

use SFW2\Gallery\GalleryException;

class Gallery extends AbstractController {

    use DateTimeHelperTrait;
    use ImageHelperTrait;
    use EMailHelperTrait;

    /**
     * @var Database
     */
    protected $database;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var User
     */
    protected $user;

    protected $title;

    const SUMMERIES_PER_PAGE = 100;

    public function __construct(int $pathId, Database $database, Config $config, User $user, string $title = null) {
        parent::__construct($pathId);
        $this->database = $database;
        $this->user = $user;
        $this->config = $config;
        $this->title = $title;
    }

    public function index($all = false) {
        unset($all);
        $content = new Content('SFW2\\Gallery\\Summary');

        $content->assign('caption',          $this->title ?? 'Gallerieübersicht');
        $content->assign('title',            $this->title);
        $content->assign('modificationDate', $this->getLastModificatonDate());
        $content->assign('webmaster',        (string)(new EMail(
            $this->config->getVal('project', 'eMailWebMaster')
        )));

        $content->appendJSFile('crud.js');
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

    public function read($all = false) {
        unset($all);
        $count = (int)filter_input(INPUT_GET, 'count', FILTER_VALIDATE_INT);
        $start = (int)filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT);

        $count = $count ? $count : 5;

        $stmt =
            "SELECT `imagegalleries`.`Id`, `imagegalleries`.`Title`, `imagegalleries`.`Description`, `imagegalleries`.`CreationDate`, " .
            "`user`.`Email`, `imagegalleries`.`PreviewImage`, " .
            "CONCAT(`user`.`FirstName`, ' ', `user`.`LastName`) AS `Creator`, " .
            "IF(`imagegalleries`.`UserId` = '%s', '1', '0') AS `OwnEntry` " .
            "FROM `{TABLE_PREFIX}_imagegalleries` AS `imagegalleries` " .
            "LEFT JOIN `{TABLE_PREFIX}_user` AS `user` " .
            "ON `user`.`Id` = `imagegalleries`.`UserId` " .
            "WHERE `imagegalleries`.`PathId` = '%s' ";

        #if(!$this->ctrl->hasCreatePermission()) {
        #    $stmt .= "AND `{TABLE_PREFIX}_imagegalleries`.`PreviewImage` != '' ";
        #}

        $stmt .= "ORDER BY `imagegalleries`.`Id` DESC ";

        $rows = $this->database->select($stmt, [$this->user->getUserId(), $this->pathId, $start, $count]);

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
            $entry['downloadLinkTitle'] = 'landesmeisterschaft_2018_in_fallingbostel.zip';
            $entry['downloadLink'     ] = '?getFile';
            $entry['previewImage'     ] = '/' . $this->getGalleryPath($row['Id']) . $row['PreviewImage'];
            $entry['creator'          ] = (string)$this->getEMail($row["Email"], $row['Creator'], 'Galerie ' . $row['Title'] . ' (' . $cd . ")");
            $entries[] = $entry;
        }
        $content->assign('entries', $entries);
        return $content;
    }

    public function showGallery($id = 1, $page = 0) {
        $stmt =
            "SELECT `imagegalleries`.`Title`, `imagegalleries`.`CreationDate`, `imagegalleries`.`Description`, `imagegalleries`.`PreviewImage`, " .
            "`user`.`Email`,  CONCAT(`user`.`FirstName`, ' ', `user`.`LastName`) AS `Creator` " .
            "FROM `{TABLE_PREFIX}_imagegalleries` AS `imagegalleries` " .
            "LEFT JOIN `{TABLE_PREFIX}_user` AS `user` " .
            "ON `user`.`Id` = `imagegalleries`.`UserId` " .
            "WHERE `imagegalleries`.`Id` = '%s' ";

        $row = $this->database->selectRow($stmt, [$id]);

        if(empty($row)) {
            throw new \SFW\Gallery\Exception('no gallery fetched!', \SFW\Gallery\Exception::NO_GALLERY_FETCHED);
        }

        $path = $this->getGalleryPath($id);

        if(!is_dir($path . '/thumb/')) {
            throw new \SFW\Gallery\Exception('path <' . $path . '> is invalid', \SFW\Gallery\Exception::INVALID_PATH);
        }

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
            $pic['pre'] = ($row['PreviewImage'] == $entry);
            $pics[] = $pic;
        }

        $dir->close();

        rsort($pics);

        $cd = $this->getDate($row['CreationDate']);
        $content = new Content('SFW2\\Gallery\\Gallery');
        $content->assign('caption',           $row['Title']);
        $content->assign('id',                (int)$id);
        $content->assign('mailaddr', (string)$this->getEMail($row["Email"], $row['Creator'], 'Galerie ' . $row['Title'] . ' (' . $cd . ")"));
        $content->assign('creationDate',      $cd);
        $content->assign('description',       $row['Description']);
        $content->assign('dllink',            '?getfile=');

        $content->assign('filename',          '$row[\'FileName\']');
        $content->assign('page',              (int)$page);

        $content->assign('pics',              $pics);

        $content->assign('editable',          true);

        $content->assign('maxFileUploads',    ini_get('max_file_uploads'));
        $content->assign('image', '/public/content/users/');/* . $this->getImageFileNameByUser()
 # FIXME: _No hardcoded path
                    'public/content/users/',
                    $row['FirstName'],
                    $row['LastName']
            ));*/
        return $content;
    }













    protected function getGalleryPath($id) {
        return 'img' . DIRECTORY_SEPARATOR . $this->pathId . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR;
    }









    public function create() {
        $tmp = [];
        $tmp['caption'] = $this->dto->getTitle('caption', true);
        $tmp['description'] = $this->dto->getTitle('description', true);

        if(
            $this->dto->getErrorProvider()->hasErrors() ||
            $this->dto->getErrorProvider()->hasWarning()
        ) {
            return
                $this->dto->getErrorProvider()->getContent() .
                $this->getSummary(0, $tmp);
        }

        $folder = \SFW\Helper::createFolder(SFW_GALLERY_PATH, $tmp['caption']);

        if(
            !mkdir(SFW_GALLERY_PATH . $folder . '/thumb'  ) ||
            !mkdir(SFW_GALLERY_PATH . $folder . '/regular') ||
            !mkdir(SFW_GALLERY_PATH . $folder . '/high'   )
        ) {
            throw new \SFW\Gallery\Exception(
                'could not create gallery-path <' .
                SFW_GALLERY_PATH . $folder . '>',
                \SFW\Gallery\Exception::COULD_NOT_CREATE_GALLERY_PATH
            );
        }

        $sections = $this->database->selectKeyValue('Module', 'DivisionId', 'sfw_division');

        $stmt =
            "INSERT INTO `sfw_media` " .
            "SET " .
            "`UserId` = '%s', " .
            "`Token` = '%s', " .
            "`Name` = '%s', " .
            "`Description` = '%s', " .
            "`DivisionId` = '%s', " .
            "`CreationDate` = NOW(), " .
            "`ActionHandler` = 'zipFolder', " .
            "`Path` = '%s', " .
            "`FileType` = 'zip', " .
            "`Deleted` = '1'," .
            "`Autogen` = '1';";

        $data = array(
            $this->user->getUserId(),
            md5($tmp['caption'] . $this->user->getUserId() . time()),
            SFW_AuxFunc::getSimplifiedName($tmp['caption']) . '.zip',
            'Alle Bilder der Galerie "' . $tmp['caption'] . '" als ZIP-Datei',
            $sections[strtolower($this->category)], // FIXME: fixen!!!
            SFW_GALLERY_PATH . $folder
        );

        $id = $this->database->insert($stmt, $data);

        $stmt =
            "INSERT INTO `sfw_imagegalleries` " .
            "SET " .
            "`PathId` = '%s', " .
            "`MediaId` = '%s', " .
            "`Description` = '%s', " .
            "`Deleted` = '0'," .
            "`Name` = '%s'";

        $data = [$this->pathId, $id, $tmp['description'], $tmp['caption']];

        $id = $this->database->insert($stmt, $data);
        $url = '/' . strtolower($this->category) . '/bilder?do=showgallery&g=' . $id . '&p=0';

        $this->ctrl->updateModificationDate();

        $view = new SFW_View();
        $view->assign('url', $url);
        $view->assignTpl('JumpTo');
        return $view->getContent();
    }

    public function delete($all = false) {
        $stmt =
            "SELECT `sfw_media`.`Path`, `sfw_media`.`Id` " .
            "FROM `sfw_imagegalleries` " .
            "LEFT JOIN `sfw_media`" .
            "ON `sfw_imagegalleries`.`MediaId` = `sfw_media`.`Id` " .
            "WHERE `sfw_imagegalleries`.`Id` = %s";

        $data = $this->database->selectRow($stmt, [$galleryId]);
        $path = SFW_GALLERY_PATH . $data['Path'] . '/.htaccess';
        file_put_contents($path, 'deny from all');

        $stmt =
            "UPDATE `sfw_media` " .
            "SET `sfw_media`.`Deleted` = '1' " .
            "WHERE `sfw_media`.`Id` = '%s'";

        if($this->database->update($stmt, [$data['Id']]) > 1) {
            $this->dto->getErrorProvider()->addError(
                SFW_Error_Provider::ERR_DEL,
                ['<NAME>' => 'Die Galerie']
            );
        }

        $stmt =
            "UPDATE `sfw_imagegalleries` " .
            "SET `sfw_imagegalleries`.`Deleted` = '1' " .
            "WHERE `sfw_imagegalleries`.`MediaId` = '%s'";

        if($this->database->update($stmt, [$data['Id']]) != 1) {
            $this->dto->getErrorProvider()->addError(
                SFW_Error_Provider::ERR_DEL,
                ['<NAME>' => 'Die Galerie']
            );
        }

        $this->dto->setSaveSuccess(true);

        return
            $this->dto->getErrorProvider()->getContent() .
            $this->getSummary($page);
    }

    public function deleteImg() {
        $this->deleteImage(
            $this->dto->getNumeric('g'),
            $this->dto->getFileName('id')
        );
        return
            $this->dto->getErrorProvider()->getContent() .
            $this->getGallery($this->dto->getNumeric('g'), $page);
    }

    public function chgprevImg() {
        $this->changePrevImg(
            $this->dto->getNumeric('g'),
            $this->dto->getFileName('id')
        );
        return
            $this->dto->getErrorProvider()->getContent() .
            $this->getGallery($this->dto->getNumeric('g'), $page);
    }

    public function addImg() {
        $galid = $this->addImg($this->dto->getNumeric('g'));

        $stmt =
            "SELECT `sfw_media`.`Path`, `sfw_imagegalleries`.`PreviewImage`, " .
            "`sfw_media`.`Id` " .
            "FROM `sfw_imagegalleries` " .
            "LEFT JOIN `sfw_media` " .
            "ON `sfw_media`.`Id` = `sfw_imagegalleries`.`MediaId` " .
            "WHERE `sfw_imagegalleries`.`Id` = '%s' ";

        $rv = $this->db->selectRow($stmt, array($galid));

        if(empty($rv)) {
            throw new SFW_Exception(__METHOD__ . ': no gallery fetched!');
        }

        if(!is_dir($rv["Path"] . '/thumb/')) {
            throw new SFW_Exception(': path <' . $rv["Path"] . '> is invalid');
        }

        $stmt =
            "UPDATE `sfw_media` " .
            "SET `sfw_media`.`Deleted` = '0' " .
            "WHERE `sfw_media`.`Id` = '%s'";

        if($this->db->update($stmt, array($rv['Id'])) != 1) {
            $this->dto->getErrorProvider()->addError(
                SFW_Error_Provider::ERR_UNDEL,
                array('<NAME>' => 'Die Galerie')
            );
        }

        $chunk = explode(';', $this->dto->getData('file'));
        $type = explode(':', $chunk[0]);
        $type = $type[1];
        $data = explode(',', $chunk[1]);

        switch($type) {
            case 'image/pjpeg':
            case 'image/jpeg':
            case 'image/jpg':
                $type = 'jpg';
                break;

            case 'image/png':
            case 'image/x-png':
                $type = 'png';
                break;

            default:
                return [
                    'error' => true,
                    'msg'   => 'Es wurde eine ungültige Datei übermittelt.' .
                    $chunk[0] . print_r($_REQUEST, true) . print_r($_FILES, true)
                ];
        }

        $cnt = count(glob($rv["Path"] . '/high/*'));
        if($cnt >= 999) {
            throw new GalleryException('more then <' . $cnt . '> images are not allowed');
        }

        $filename = str_repeat('0', 4 - mb_strlen('' . $cnt)) . ++$cnt . '.' . $type;

        if(!file_put_contents($rv["Path"] . '/high/' . $filename, base64_decode($data[1]))) {
            throw new GalleryException("could not store file <$filename> in path <" . $rv["Path"] . '/high/>');
        }

        $this->generateThumb($filename, 170, $rv["Path"] . '/high/', $rv["Path"] . '/thumb/');
        $this->generateThumb($filename, 335, $rv["Path"] . '/high/', $rv["Path"] . '/regular/');

        if($rv['PreviewImage'] == '') {
            $this->changePrevImg($galid, $filename);
        }

        $this->ctrl->updateModificationDate();

        return array(
            'error' => false,
            'msg' => 'Alles chick.'
        );



            }







    protected function deleteImage($galid, $fileName) {
        if(!$this->ctrl->hasDeletePermission()) {
            return false;
        }

        $stmt =
            "SELECT `sfw_media`.`Path`, `sfw_imagegalleries`.`PreviewImage` " .
            "FROM `sfw_imagegalleries` " .
            "LEFT JOIN `sfw_media` " .
            "ON `sfw_media`.`Id` = `sfw_imagegalleries`.`MediaId` " .
            "LEFT JOIN `sfw_division` " .
            "ON `sfw_division`.`DivisionId` = `sfw_media`.`DivisionId` " .
            "WHERE `sfw_imagegalleries`.`Id` = '%s' " .
            "AND `sfw_division`.`Module` = '%s' ";

        $rv = $this->db->selectRow($stmt, array($galid, $this->category));

        if(empty($rv)) {
            throw new GalleryException('no valid gallery fetched!', GalleryException::NO_GALLERY_FETCHED);
        }

        if($fileName == $rv['PreviewImage']) {
            throw new GalleryException('unable to delete preview-img!', GalleryException::COULD_NOT_DELETE_PREVIEW_IMAGE);
        }

        unlink($rv['Path'] . '/thumb/' . $fileName);
        unlink($rv['Path'] . '/regular/' . $fileName);
        unlink($rv['Path'] . '/high/' . $fileName);
        $this->dto->setSaveSuccess(treu);

        return true;
    }

    private function getPreviewPath($path, $file) {
        #if($file == '') {
            return '/public/images/content/thumb/empty.png';
        #}

        if(is_file($path . '/thumb/' . $file)) {
            return '/' . $path . '/thumb/' . $file;
        }
        throw new GalleryException("preview <$path/thumb/$file> does not exist", GalleryException::PREVIEW_FILE_DOES_NOT_EXIST);
    }

    private function generateThumb($file, $size, $src, $des) {
        if(!is_file($src . '/' . $file)) {
            return false;
        }

        list($srcWidth, $srcHeight, $srcTyp) = getimagesize($src . '/' . $file);

        if($srcWidth >= $srcHeight) {
            $desWidth = $size;
            $desHeight = $srcHeight / $srcWidth * $size;
        } else {
            $desHeight = $size;
            $desWidth = $srcWidth / $srcHeight * $size;
        }

        switch($srcTyp) {
            case IMAGETYPE_JPEG:
                $old = imagecreatefromjpeg($src . '/' . $file);
                $new = imagecreatetruecolor($desWidth, $desHeight);
                imagecopyresampled($new, $old, 0, 0, 0, 0, $desWidth, $desHeight, $srcWidth, $srcHeight);
                imagejpeg($new, $des . '/' . $file, 100);
                imagedestroy($old);
                imagedestroy($new);
                return true;

            case IMAGETYPE_PNG:
                $old = imagecreatefrompng($src . '/' . $file);
                $new = imagecreatetruecolor($desWidth, $desHeight);
                imagecopyresampled($new, $old, 0, 0, 0, 0, $desWidth, $desHeight, $srcWidth, $srcHeight);
                imagepng($new, $des . '/' . $file);
                imagedestroy($old);
                imagedestroy($new);
                return true;
        }
    }

    private function changePrevImg($id, $fileName) {
        if(!$this->ctrl->hasCreatePermission()) {
            return false;
        }

        $stmt =
            "SELECT `sfw_media`.`Path`, `sfw_imagegalleries`.`PreviewImage` " .
            "FROM `sfw_imagegalleries` " .
            "LEFT JOIN `sfw_media` " .
            "ON `sfw_media`.`Id` = `sfw_imagegalleries`.`MediaId` " .
            "LEFT JOIN `sfw_division` " .
            "ON `sfw_division`.`DivisionId` = `sfw_media`.`DivisionId` " .
            "WHERE `sfw_imagegalleries`.`Id` = '%s' " .
            "AND `sfw_division`.`Module` = '%s' ";

        $rv = $this->db->selectRow($stmt, array($id, $this->category));

        if(empty($rv)) {
            throw new GalleryException('no valid gallery fetched!', GalleryException::NO_GALLERY_FETCHED);
        }

        if($fileName == $rv['PreviewImage']) {
            throw new GalleryException('unable to change preview-img!', GalleryException::COULD_NOT_CHANGE_PREVIEW_IMAGE);
        }

        // Prüfen, ob Bild im Ordner vorhanden...
        $file = $rv["Path"] . '/thumb/' . $fileName;

        if(!is_file($file)) {
            throw new GalleryException("file <$file> is not a valid image!", GalleryException::INVALID_IMAGE);
        }

        $stmt =
            "UPDATE `sfw_imagegalleries` " .
            "SET `PreviewImage` = '%s' " .
            "WHERE `Id` = %s";

        if($this->db->update($stmt, array($fileName, $id)) != 1) {
            throw new GalleryException('updating imagegalleries failed!', GalleryException::UPDATING_GALLERY_FAILED);
        }
        $this->dto->setSaveSuccess();
        return true;
    }
}
