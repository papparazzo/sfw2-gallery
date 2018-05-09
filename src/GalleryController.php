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





/**
 * class Exception extends \SFW\Exception {
    const INVALID_PATH                      = 2;
    const INVALID_IMAGE                     = 9;
    const COULD_NOT_DELETE_PREVIEW_IMAGE    = 3;
    const PREVIEW_FILE_DOES_NOT_EXIST       = 4;
    const COULD_NOT_CREATE_GALLERY_PATH     = 5;
    const COULD_NOT_CHANGE_PREVIEW_IMAGE    = 1;
    const COULD_NOT_INSERT_INTO_MEDIA_TABLE = 6;
    const INSERTATION_OF_GALLERY_FAILED     = 7;
    const NO_GALLERY_FETCHED                = 8;
    const UPDATING_GALLERY_FAILED           = 10;
}
 */

namespace SFW2\Gallery;

use SFW2\Routing\AbstractController;
use SFW2\Routing\Result\Content;
use SFW2\Controllers\Widget\Obfuscator\EMail;
use SFW2\Authority\User;
use SFW2\Core\Database;
use SFW2\Core\Config;

use SFW2\Controllers\Controller\Helper\DateTimeHelperTrait;
use SFW2\Controllers\Controller\Helper\ImageHelperTrait;
use SFW2\Controllers\Controller\Helper\EMailHelperTrait;

class GalleryController extends AbstractController {

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
        $content = new Content('SFW2\\Gallery\\Summary');

        $content->assign('caption',    $this->title ? $this->title : 'Gallerieübersicht');
        $content->assign('title',      $this->title);
        #FIXME $view->assign('modiDate',   $this->ctrl->getModificationDate());
        $content->assign('webmaster',  (string)(new EMail(
            $this->config->getVal('project', 'eMailWebMaster')
        )));

        $content->appendJSFile('crud.js');
        return $content;
    }

    public function loadSummary($page = 0) {
        $offset = $page * self::SUMMERIES_PER_PAGE;

        $stmt =
            "SELECT `sfw2_imagegalleries`.`Id`, `sfw2_imagegalleries`.`Title`, " .
            "`sfw2_imagegalleries`.`Description`, `sfw2_imagegalleries`.`CreationDate`, " .
            "`sfw2_user`.`Email`, `sfw2_imagegalleries`.`PreviewImage`, " .
            "CONCAT(`sfw2_user`.`FirstName`, ' ', `sfw2_user`.`LastName`) AS `Creator`, " .
            "IF(`sfw2_imagegalleries`.`UserId` = '%s', '1', '0') AS `OwnEntry` " .
            "FROM `sfw2_imagegalleries` " .
            "LEFT JOIN `sfw2_user` " .
            "ON `sfw2_user`.`Id` = `sfw2_imagegalleries`.`UserId` " .
            "WHERE `sfw2_imagegalleries`.`PathId` = '%s' ";

        #if(!$this->ctrl->hasCreatePermission()) {
        #    $stmt .= "AND `sfw2_imagegalleries`.`PreviewImage` != '' ";
        #}

        $stmt .= "ORDER BY `sfw2_imagegalleries`.`Id` DESC ";

        $rows = $this->database->select($stmt, [$this->user->getUserId(), $this->pathId, $offset, self::SUMMERIES_PER_PAGE]);
        $content = new Content();
        $entries = [];

        foreach($rows as $row) {
            $cd = $this->getShortDate($row['CreationDate']);
            $entry = [];
            $entry['id'          ] = $row['Id'];
            $entry['date'        ] = $cd;
            $entry['title'       ] = $row['Title'];
            $entry['description' ] = $row['Description'];
            $entry['ownEntry'    ] = (bool)$row['OwnEntry'];
            $entry['previewImage'] = '/img/33/1/' . $row['PreviewImage'];
            $entry['creator'     ] = (string)$this->getEMail($row["Email"], $row['Creator'], 'Galerie ' . $row['Title'] . ' (' . $cd . ")");
            $entries[] = $entry;
        }
        $content->assign('entries', $entries);
        return $content;
    }





    protected function getGallery($id, $page = 0) {
        $stmt =
            "SELECT `sfw2_imagegalleries`.`Name`, `sfw2_imagegalleries`.`CreationDate`, " .
            "`sfw2_imagegalleries`.`Description`, `sfw2_imagegalleries`.`PreviewImage`, " .
            "`sfw2_users`.`Email`,  " .
            "CONCAT(`sfw2_users`.`FirstName`, ' ', `sfw2_users`.`LastName`) AS `Creator` " .
            "FROM `sfw2_imagegalleries` " .
            "LEFT JOIN `sfw2_users` " .
            "ON `sfw2_users`.`Id` = `sfw2_imagegalleries`.`UserId` " .
            "WHERE `sfw2_imagegalleries`.`Id` = '%s' " .
            "AND `sfw_division`.`Module` = '%s' ";

        $rv = $this->database->selectRow($stmt, [$id]);

        if(empty($rv)) {
            throw new \SFW\Gallery\Exception(
                'no gallery fetched!',
                \SFW\Gallery\Exception::NO_GALLERY_FETCHED
            );
        }
        if(!is_dir($rv['Path'] . '/thumb/')) {
            throw new \SFW\Gallery\Exception(
                'path <' . $rv['Path'] . '> is invalid',
                \SFW\Gallery\Exception::INVALID_PATH
            );
        }

        $dir = dir($rv['Path'] . '/thumb/');
        $pics = array();

        while(false !== ($entry = $dir->read())) {
            if($entry == '.' || $entry == '..') {
                continue;
            }

            $fi = pathinfo($rv['Path'] . '/thumb/' . $entry);

            if(
                strtolower($fi['extension']) != 'jpg' &&
                strtolower($fi['extension']) != 'png'
            ) {
                continue;
            }

            $pic = array();
            $pic['lnk'] = '/' . $rv['Path'] . '/high/' . $entry;
            $pic['ttp'] = $entry;
            $pic['src'] = '/' . $rv['Path'] . '/thumb/' . $entry;
            $pic['pre'] = ($rv['PreviewImage'] == $entry);
            $pics[] = $pic;
        }

        $dir->close();

        rsort($pics);

        $crDate = new \SFW\View\Helper\Date($rv['CreationDate'], new \SFW\Locale());
        $view   = new \SFW\View();
        $view->assign('name',              $rv['Name']);
        $view->assign('filename',          $rv['FileName']);
        $view->assign('page',              (int)$page);
        $view->assign('description',       $rv['Description']);
        $view->assign('creationDate',      $crDate);
        $view->assign('pics',              $pics);
        $view->assign('dllink',            '?getfile=' . $rv['Token']);
        $view->assign('editable',          $this->ctrl->hasDeletePermission());
        $view->assign('galId',             (int)$id);
        $view->assign('maxFileUploads',    ini_get('max_file_uploads'));
        $view->assign('mailaddr',          new \SFW\View\Helper\Obfuscator\EMail(
            $rv['Email'],
            $rv['Creator'],
            'Galerie ' . $rv['Name'] . ' (' .
            $crDate->getFormatedDate(true) . ')'
        ));
        $view->assign('image', '/public/content/users/' . \SFW\Helper::getImageFileName(
 # FIXME: _No hardcoded path
                    'public/content/users/',
                    $row['FirstName'],
                    $row['LastName']
            ));
        $view->assignTpl(
            $this->conf->getTemplateFile('Gallery/Gallery')
        );
        return $view->getContent();
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

    public function showgallery() {
        return $this->getGallery(45); #$this->dto->getNumeric('g'), $page);
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
            throw new SFW_Exception(
                __METHOD__ . ': path <' . $rv["Path"] . '> is invalid'
            );
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
                return array(
                    'error' => true,
                    'msg'   => 'Es wurde eine ungültige Datei übermittelt.' .
                    $chunk[0] . print_r($_REQUEST, true) . print_r($_FILES, true)
                );
        }

        $cnt = count(glob($rv["Path"] . '/high/*'));
        if($cnt >= 999) {
            throw new SFW_Exception(
                'more then <' . $cnt . '> images are not allowed'
            );
        }

        $filename = str_repeat('0', 4 - mb_strlen('' . $cnt)) . ++$cnt . '.' . $type;

        if(!file_put_contents(
            $rv["Path"] . '/high/' . $filename,
            base64_decode($data[1]))
        ) {
            throw new SFW_Exception(
                'could not store file <' . $filename .
                '> in path <' . $rv["Path"] . '/high/>'
            );
        }

        $this->generateThumb(
            $filename,
            170,
            $rv["Path"] . '/high/', $rv["Path"] . '/thumb/'
        );
        $this->generateThumb(
            $filename,
            335,
            $rv["Path"] . '/high/', $rv["Path"] . '/regular/'
        );

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
            throw new \SFW\Gallery\Exception(
                'no valid gallery fetched!',
                \SFW\Gallery\Exception::NO_GALLERY_FETCHED
            );
        }

        if($fileName == $rv['PreviewImage']) {
            throw new \SFW\Gallery\Exception(
                'unable to delete preview-img!',
                \SFW\Gallery\Exception::COULD_NOT_DELETE_PREVIEW_IMAGE
            );
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
        throw new \SFW\Gallery\Exception(
            'preview <' . $path . '/thumb/' . $file . '> does not exist',
            \SFW\Gallery\Exception::PREVIEW_FILE_DOES_NOT_EXIST
        );
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
                imagecopyresampled(
                    $new,
                    $old,
                    0,
                    0,
                    0,
                    0,
                    $desWidth,
                    $desHeight,
                    $srcWidth,
                    $srcHeight
                );
                imagejpeg($new, $des . '/' . $file, 100);
                imagedestroy($old);
                imagedestroy($new);
                return true;

            case IMAGETYPE_PNG:
                $old = imagecreatefrompng($src . '/' . $file);
                $new = imagecreatetruecolor($desWidth, $desHeight);
                imagecopyresampled(
                    $new,
                    $old,
                    0,
                    0,
                    0,
                    0,
                    $desWidth,
                    $desHeight,
                    $srcWidth,
                    $srcHeight
                );
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
            throw new \SFW\Gallery\Exception(
                'no valid gallery fetched!',
                \SFW\Gallery\Exception::NO_GALLERY_FETCHED
            );
        }

        if($fileName == $rv['PreviewImage']) {
            throw new \SFW\Gallery\Exception(
                'unable to change preview-img!',
                \SFW\Gallery\Exception::COULD_NOT_CHANGE_PREVIEW_IMAGE
            );
        }

        // Prüfen, ob Bild im Ordner vorhanden...
        $file = $rv["Path"] . '/thumb/' . $fileName;

        if(!is_file($file)) {
            throw new \SFW\Gallery\Exception(
                'file <' . $file . '> is not a valid image!',
                \SFW\Gallery\Exception::INVALID_IMAGE
            );
        }

        $stmt =
            "UPDATE `sfw_imagegalleries` " .
            "SET `PreviewImage` = '%s' " .
            "WHERE `Id` = %s";

        if($this->db->update($stmt, array($fileName, $id)) != 1) {
            throw new \SFW\Gallery\Exception(
                'updating imagegalleries failed!',
                \SFW\Gallery\Exception::UPDATING_GALLERY_FAILED
            );
        }
        $this->dto->setSaveSuccess();
        return true;
    }
}