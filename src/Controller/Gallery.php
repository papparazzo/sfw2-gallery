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

use DateTime;
use DateTimeZone;
use Exception;
use Fig\Http\Message\StatusCodeInterface;
use IntlDateFormatter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SFW2\Core\HttpExceptions\HttpUnprocessableContent;
use SFW2\Database\DatabaseInterface;
use SFW2\Routing\AbstractController;

use SFW2\Routing\HelperTraits\getPathTrait;
use SFW2\Routing\ResponseEngine;
use SFW2\Validator\Ruleset;
use SFW2\Validator\Validator;
use SFW2\Validator\Validators\IsNotEmpty;

use SFW2\Gallery\Helper\GalleryHelperTrait;
use SFW2\Gallery\GalleryException;

class Gallery extends AbstractController {

    use getPathTrait;

    use GalleryHelperTrait;

    protected ?string $title;

    const SUMMERIES_PER_PAGE = 3;
    const PREVIEW_FILE = 'preview.png';
    const PREVIEW_FILE_BIG = 'previewb.png';
    const DIMENSIONS = 130;
    const DIMENSIONS_BIG = 270;

    public function __construct(
        protected DatabaseInterface $database
        /*
        Config $config, User $user, string $title = null*/) {
       /*
        $this->database = $database;
        $this->user = $user;
        $this->config = $config;
        $this->title = $title;
       */
    }

    public function index(Request $request, ResponseEngine $responseEngine): Response
    {
        $pathId = $this->getPathId($request);

        $stmt =
            "SELECT `Id`, `Title`, `Description`, `CreationDate` " .
            "FROM `{TABLE_PREFIX}_imagegalleries` AS `imagegalleries` " .
            "WHERE `PathId` = %s " .
            "ORDER BY `Id` DESC ";

        $rows = $this->database->select($stmt, [$pathId]);
        $entries = [];

        foreach($rows as $row) {
           # $cd = $this->getShortDate($row['CreationDate']);
            $entry = [];
            $entry['id'               ] = $row['Id'];
           # $entry['date'             ] = $cd;
            $entry['title'            ] = $row['Title'];
            $entry['link'             ] = '?do=showGallery&id=' . $row['Id'];
            $entry['description'      ] = $row['Description'];
            #$entry['ownEntry'         ] = (bool)$row['OwnEntry'];
            $entry['previewImage'     ] = $this->getPreviewImage($pathId, $row['Id'], self::PREVIEW_FILE_BIG);
            #$entry['creator'          ] = (string)$this->getEMail($row["Email"], $row['Creator'], 'Galerie ' . $row['Title'] . ' (' . $cd . ")");
            $entries[] = $entry;
        }

        $content = [
            'caption' => 'Bildergalerie',
            'webmaster' => $request->getAttribute('sfw2_project')['webmaster_mail_address'],
            'entries' => $entries
        ];

        return $responseEngine->render(
            $request,
            $content,
            "SFW2\\Gallery\\Summary",
        );
    }

    /**
     * @throws HttpUnprocessableContent
     */
    public function showGallery(Request $request, ResponseEngine $responseEngine): Response
    {

        $pathId = $this->getPathId($request);

        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if(!$id) {
            throw new HttpUnprocessableContent("no gallery fetched!");
        }
        $page = (int)filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);

        $stmt =
            "SELECT `Title`, `CreationDate`, `Description` " .
            "FROM `{TABLE_PREFIX}_imagegalleries` AS `imagegalleries` " .
            "WHERE `imagegalleries`.`Id` = %s ";

        $row = $this->database->selectRow($stmt, [$id]);

        if(empty($row)) {
            throw new HttpUnprocessableContent("no gallery found for id <$id>!");
        }
        $path = $this->getGalleryPath($pathId, $id);
        $pics = [];
        if(is_dir($path . '/thumb/')) {
            $pics = $this->getImageList($path);
        }

        $cd = $this->getShortDate($row['CreationDate']);
        $content = [
            'title'          => $row['Title'],
            'id'             => (int)$id,
        #'mailaddr' =>          (string)$this->getEMail($row["Email"], $row['Creator'], 'Galerie ' . $row['Title'] . ' (' . $cd . ")"));
            'creationDate'   => $cd,
            'description'    => $row['Description'],
            'page'           => $page,
            'pics'           => $pics,
            'editable'       => true,
            'previewImage'   => $this->getPreviewImage($pathId, $id),
            'maxFileUploads' => ini_get('max_file_uploads')
        ];

        return $responseEngine->render(
            $request,
            $content,
            "SFW2\\Gallery\\Gallery"
        );
    }

    /**
     * @noinspection PhpMissingParentCallCommonInspection
     */
    public function create(Request $request, ResponseEngine $responseEngine): Response
    {
        $rulset = new Ruleset();
        $rulset->addNewRules('caption', new IsNotEmpty());
        $rulset->addNewRules('description', new IsNotEmpty());

        $validator = new Validator($rulset);
        $values = [];

        $error = $validator->validate($_POST, $values);

        if(!$error) {
            return
                $responseEngine->
                render($request, ['sfw2_payload' => $values])->
                withStatus(StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY);
        }

        $stmt =
            "INSERT INTO `{TABLE_PREFIX}_imagegalleries` " .
            "SET `PathId` = '%s', `UserId` = '%s', `CreationDate` = NOW(), `Title` = '%s', `Description` = '%s' ";

        $id = $this->database->insert(
            $stmt,
            [
                $this->getPathId($request),
                1,//$this->user->getUserId(), // FIXME
                $values['caption']['value'],
                $values['description']['value'],
            ]
        );

        return $responseEngine->render(
            $request,
            [],
            "SFW2\\Gallery\\Gallery"
        );

      /*


        $cd = $this->getShortDate();
        $content = new Content('Gallery');
        $content['id'] =           ['value' => $id];
        $content['date'] =         ['value' => $cd];
        $content['title'] =        ['value' => $values['caption']['value']];
        $content['description'] =  ['value' => $values['description']['value']];
        $content['link'] =         ['value' => '?do=showGallery&id=' . $id];
        $content->assign('ownEntry',     ['value' => true]);
        $content->assign('previewImage', ['value' => $this->getPreviewImage()]);
        $content['creator'] =  ['value' => $this->getEMailByUser($this->user, 'Galerie ' . $values['caption']['value'] . ' (' . $cd . ")")];

        $content->dataWereModified();
        return $content;
      */
    }

    /**
     * @throws \SFW2\Gallery\GalleryException
     * @throws ResolverException
     * @noinspection PhpMissingParentCallCommonInspection
     */
    public function delete(Request $request, ResponseEngine $responseEngine): Response
    {
        $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_STRING);
        if(!$id) {
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
     * @throws HttpUnprocessableContent
     */
    public function changePreview(Request $request, ResponseEngine $responseEngine): Response
    {
        $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_STRING);
        $p = strpos($id, '__');
        if($p === false) {
            throw new HttpUnprocessableContent();
        }

        $file = substr($id, $p + 2);
        $galleryId = substr($id, 0, $p);

        $path = $this->getGalleryPath($galleryId);
        $this->generatePreview($file, self::DIMENSIONS, $path . '/high/', $path . '/' . self::PREVIEW_FILE);
        $this->generatePreview($file, self::DIMENSIONS_BIG, $path . '/high/', $path . '/' . self::PREVIEW_FILE_BIG);
        return $responseEngine->render($request);
    }

    /**
     * @throws ResolverException
     * @throws \Exception
     */
    public function rotateImage(Request $request, ResponseEngine $responseEngine): Response
    {
        $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_STRING);
        $p = strpos($id, '__');
        if($p === false) {
            throw new HttpUnprocessableContent();
        }

        $file = substr($id, $p + 2);
        $galleryId = substr($id, 0, $p);

        $path = $this->getGalleryPath($galleryId);

        $this->rotate($path . 'thumb/' . $file);
        $this->rotate($path . 'high/' . $file);
        return $responseEngine->render($request);
    }

    /**
     * @throws \Exception
     */
    protected function rotate(string $file): void
    {
        [, , $srcTyp] = getimagesize($file);
        switch ($srcTyp) {
            case IMAGETYPE_JPEG:
                $old = imagecreatefromjpeg($file);
                break;

            case IMAGETYPE_PNG:
                $old = imagecreatefrompng($file);
                break;

            default:
                throw new Exception("Invalid Image type <$srcTyp> given");
        }

        $new = imagerotate($old, 180, 0);

        imagepng($new, $file);
        imagedestroy($new);
        imagedestroy($old);
    }

    protected function getGalleryPath(int $pathId, int $galleryId): string {
        return 'img' . DIRECTORY_SEPARATOR . $pathId . DIRECTORY_SEPARATOR . $galleryId . DIRECTORY_SEPARATOR;
    }

    protected function getPreviewImage(int $pathId, int $id = 0, string $previewFile = self::PREVIEW_FILE): string {
        if($id == 0) {
            return "/img/layout/empty.png";
        }
        $preview = $this->getGalleryPath($pathId, $id) . $previewFile;

        if(!is_file($preview)) {
         #   return "/img/layout/empty.png";
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
            unlink($path . '/' . self::PREVIEW_FILE_BIG);
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
            "SELECT `imagegalleries`.`Id` FROM `{TABLE_PREFIX}_imagegalleries` AS `imagegalleries` " .
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
    public function addImage(Request $request, ResponseEngine $responseEngine): Response
    {
        $galleryId = filter_input(INPUT_POST, 'gallery', FILTER_SANITIZE_STRING);

        $folder = $this->getGalleryPath($galleryId);

        $filename = $this->addFile($folder, self::DIMENSIONS);

        $highFolder = $folder . DIRECTORY_SEPARATOR . 'high' . DIRECTORY_SEPARATOR;

        if(!is_file($folder . self::PREVIEW_FILE)) {
            $this->generatePreview($filename, self::DIMENSIONS, $highFolder, $folder . '/' . self::PREVIEW_FILE);
            $this->generatePreview($filename, self::DIMENSIONS_BIG, $highFolder, $folder . '/' . self::PREVIEW_FILE_BIG);
        }

        return $responseEngine->render($request);
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

        [$oldW, $oldH, $srcTyp] = getimagesize($srcFile);


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

            default:
                throw new Exception("Invalid Image type <$srcTyp> given");
        }

        imagecopyresampled($new, $old, 0, 0, ($newW - $dimensions) / 2, ($newH - $dimensions) / 2, $dimensions, $dimensions, $limD, $limD);
        imagepng($new, $des);
        imagedestroy($new);
        imagedestroy($old);
    }

    protected function getImageList(string $path): array{
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

    protected function getLastModificatonDate() {
        $stmt =
            "SELECT `imagegalleries`.`CreationDate` FROM `{TABLE_PREFIX}_imagegalleries` AS `imagegalleries` " .
            "WHERE `imagegalleries`.`PathId` = '%s' " .
            "ORDER BY `imagegalleries`.`CreationDate`";

        return $this->database->selectSingle($stmt, [$this->pathId]);
    }


        // TODO: Make this a trait
    /**
     * @throws Exception
     * @deprecated
     */
    protected function getShortDate($date = 'now', string $dateTimeZone = 'Europe/Berlin'): bool|string
    {
        if($date === null) {
            return '';
        }

         $local_date = IntlDateFormatter::create(
                'de',
                IntlDateFormatter::LONG,
                IntlDateFormatter::NONE,
                $dateTimeZone,
                null,
                null
            );

        return $local_date->format(new DateTime($date, new DateTimeZone($dateTimeZone)));
    }
}
