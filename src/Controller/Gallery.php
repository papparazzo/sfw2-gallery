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

use Exception;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SFW2\Core\HttpExceptions\HttpBadRequest;
use SFW2\Core\HttpExceptions\HttpNotFound;
use SFW2\Core\HttpExceptions\HttpUnprocessableContent;
use SFW2\Core\Permission\AccessType;
use SFW2\Core\Permission\PermissionInterface;
use SFW2\Core\Utils\DateTimeHelper;
use SFW2\Database\DatabaseException;
use SFW2\Database\DatabaseInterface;
use SFW2\Database\QueryHelper;
use SFW2\Gallery\Helper\ImageHelperTrait;
use SFW2\Routing\AbstractController;

use SFW2\Routing\HelperTraits\getRoutingDataTrait;
use SFW2\Routing\ResponseEngine;
use SFW2\Validator\Ruleset;
use SFW2\Validator\Validator;
use SFW2\Validator\Validators\IsNotEmpty;

final class Gallery extends AbstractController
{

    use getRoutingDataTrait;

    use ImageHelperTrait;

    const PREVIEW_FILE = 'preview.png';
    const PREVIEW_FILE_BIG = 'previewb.png';
    const DIMENSIONS = 130;
    const DIMENSIONS_BIG = 270;

    private QueryHelper $queryHelper;

    public function __construct(
        private readonly DatabaseInterface   $database,
        private readonly DateTimeHelper      $dateTimeHelper,
        private readonly PermissionInterface $permission
    )
    {
        $this->queryHelper = new QueryHelper($this->database);
    }

    /**
     * @throws HttpNotFound
     * @throws Exception
     */
    public function index(Request $request, ResponseEngine $responseEngine): Response
    {
        $pathId = $this->getPathId($request);

        $stmt = /** @lang MySQL */
            "SELECT `Id`, `Title`, `Description`, `CreationDate` " .
            "FROM `{TABLE_PREFIX}_gallery_imagegalleries` AS `imagegalleries` " .
            "WHERE `PathId` = %s " .
            "ORDER BY `Id` DESC ";

        $rows = $this->database->select($stmt, [$pathId]);
        $entries = [];

        $deleteAllowed = $this->permission->checkPermission($pathId, 'delete');

        foreach ($rows as $row) {
            $entry = [];
            $entry['id'               ] = $row['Id'];
            $entry['date'             ] = $this->dateTimeHelper->getDate(DateTimeHelper::FULL_DATE, $row['CreationDate']);
            $entry['title'            ] = $row['Title'];
            $entry['link'             ] = '?do=showGallery&id=' . $row['Id'];
            $entry['description'      ] = $row['Description'];
            $entry['delete_allowed'   ] = $deleteAllowed !== AccessType::VORBIDDEN;
            #$entry['ownEntry'         ] = (bool)$row['OwnEntry']; // FIXME
            $entry['previewImage'     ] = $this->getPreviewImage($pathId, $row['Id'], self::PREVIEW_FILE_BIG);
            #$entry['creator'          ] = (string)$this->getEMail($row["Email"], $row['Creator'], 'Galerie ' . $row['Title'] . ' (' . $cd . ")");
            $entries[] = $entry;
        }

        $content = [
            'caption' => 'Bildergalerie',
            'entries' => $entries,
            'webmaster' => $request->getAttribute('sfw2_project')['webmaster_mail_address'],
            'create_allowed' => $this->permission->checkPermission($pathId, 'create') !== AccessType::VORBIDDEN,
            'update_allowed' => $this->permission->checkPermission($pathId, 'update') !== AccessType::VORBIDDEN,
            'delete_allowed' => $this->permission->checkPermission($pathId, 'delete') !== AccessType::VORBIDDEN,
        ];

        return $responseEngine->render($request, $content, "SFW2\\Gallery\\Summary");
    }

    /**
     * @throws HttpUnprocessableContent|HttpNotFound
     * @throws HttpBadRequest
     * @throws Exception
     */
    public function showGallery(Request $request, ResponseEngine $responseEngine): Response
    {
        $pathId = $this->getPathId($request);

        $id = (int)filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            throw new HttpUnprocessableContent("no gallery fetched!");
        }
        $page = (int)filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);

        $stmt = /** @lang MySQL */
            "SELECT `Title`, `CreationDate`, `Description` " .
            "FROM `{TABLE_PREFIX}_gallery_imagegalleries` AS `imagegalleries` " .
            "WHERE `imagegalleries`.`Id` = %s ";

        $row = $this->queryHelper->selectRow($stmt, [$id]);

        if (empty($row)) {
            throw new HttpBadRequest("no gallery found for id <$id>!");
        }
        $path = $this->getGalleryPath($pathId, $id);
        $pics = [];
        if (is_dir($path . '/thumb/')) {
            $pics = $this->getImageList($path);
        }

        $content = [
            'title'          => $row['Title'],
            'id'             => $id,
            #'mailaddr' =>          (string)$this->getEMail($row["Email"], $row['Creator'], 'Galerie ' . $row['Title'] . ' (' . $cd . ")"));
            'creationDate'   => $this->dateTimeHelper->getDate(DateTimeHelper::FULL_DATE, $row['CreationDate']),
            'description'    => $row['Description'],
            'page'           => $page,
            'pics'           => $pics,
            'editable'       => true,
            'previewImage'   => $this->getPreviewImage($pathId, $id),
            'maxFileUploads' => ini_get('max_file_uploads'),
            'create_allowed' => $this->permission->checkPermission($pathId, 'create') !== AccessType::VORBIDDEN,
            'update_allowed' => $this->permission->checkPermission($pathId, 'update') !== AccessType::VORBIDDEN,
            'delete_allowed' => $this->permission->checkPermission($pathId, 'delete') !== AccessType::VORBIDDEN,
        ];

        return $responseEngine->render($request, $content, "SFW2\\Gallery\\Gallery");
    }

    /**
     * @noinspection PhpMissingParentCallCommonInspection
     * @throws DatabaseException
     * @throws HttpNotFound
     */
    public function create(Request $request, ResponseEngine $responseEngine): Response
    {
        $rulset = new Ruleset();
        $rulset->addNewRules('caption', new IsNotEmpty());
        $rulset->addNewRules('description', new IsNotEmpty());

        $validator = new Validator($rulset);
        $values = [];

        $error = $validator->validate($_POST, $values);

        if (!$error) {
            return
                $responseEngine->
                render($request, ['sfw2_payload' => $values])->
                withStatus(StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY);
        }

        $stmt = /** @lang MySQL */
            "INSERT INTO `{TABLE_PREFIX}_gallery_imagegalleries` " .
            "SET `PathId` = %s, `UserId` = %s, `CreationDate` = NOW(), `Title` = %s, `Description` = %s ";

        $this->database->insert(
            $stmt,
            [
                $this->getPathId($request),
                1,//$this->user->getUserId(), // FIXME
                $values['caption']['value'],
                $values['description']['value'],
            ]
        );

        return $responseEngine->render(request: $request, template: "SFW2\\Gallery\\Gallery");
    }

    /**
     * @noinspection PhpMissingParentCallCommonInspection
     * @throws HttpBadRequest
     * @throws HttpNotFound
     * @throws DatabaseException
     */
    public function delete(Request $request, ResponseEngine $responseEngine): Response
    {
        $all = true; // FIXME!

        $pathId = $this->getPathId($request);
        $id = htmlspecialchars($_POST['id']);
        if (!$id) {
            throw new HttpBadRequest("invalid entry-id given");
        }

        $tokens = explode('__', $id);
        if (count($tokens) == 1) {
            $this->deleteGallery($pathId, $tokens[0], $all);
        } else {
            $this->deleteImage($pathId, array_shift($tokens), implode('__', $tokens), $all);
        }
        return $responseEngine->render($request);
    }

    /**
     * @throws HttpUnprocessableContent
     * @throws HttpNotFound
     * @throws Exception
     */
    public function changePreview(Request $request, ResponseEngine $responseEngine): Response
    {
        $id = htmlspecialchars($_POST['id']);
        $p = strpos($id, '__');
        if ($p === false) {
            throw new HttpUnprocessableContent();
        }
        $pathId = $this->getPathId($request);

        $file = substr($id, $p + 2);
        $galleryId = substr($id, 0, $p);

        $path = $this->getGalleryPath($pathId, $galleryId);
        $this->generatePreview($file, self::DIMENSIONS, $path . '/high/', $path . '/' . self::PREVIEW_FILE);
        $this->generatePreview($file, self::DIMENSIONS_BIG, $path . '/high/', $path . '/' . self::PREVIEW_FILE_BIG);
        return $responseEngine->render($request);
    }

    /**
     * @throws \Exception
     */
    public function rotateImage(Request $request, ResponseEngine $responseEngine): Response
    {
        $id = htmlspecialchars($_POST['id']);
        $p = strpos($id, '__');
        if ($p === false) {
            throw new HttpUnprocessableContent();
        }

        $file = substr($id, $p + 2);
        $galleryId = substr($id, 0, $p);

        $pathId = $this->getPathId($request);
        $path = $this->getGalleryPath($pathId, $galleryId);

        $this->rotate($path . 'thumb/' . $file);
        $this->rotate($path . 'high/' . $file);
        return $responseEngine->render($request);
    }

    protected function getPreviewImage(int $pathId, int $id = 0, string $previewFile = self::PREVIEW_FILE): string
    {
        if ($id == 0) {
            return "/img/layout/empty.png";
        }
        $preview = $this->getGalleryPath($pathId, $id) . $previewFile;

        if (!is_file($preview)) {
            return "/img/layout/empty.png";
        }
        return '/' . $preview;
    }

    /**
     * @throws HttpNotFound
     * @throws DatabaseException
     */
    protected function deleteGallery(int $pathId, int $galleryId, bool $all = false): void
    {
        $stmt = "DELETE FROM `{TABLE_PREFIX}_gallery_imagegalleries` WHERE `Id` = %s AND `PathId` = %s";

        #if(!$all) {
        #    $stmt .= "AND `UserId` = '" . $this->database->escape($this->user->getUserId()) . "'";
        #}

        if (!$this->database->delete($stmt, [$galleryId, $pathId])) {
            throw new HttpNotFound("no entry found for id <$galleryId>");
        }

        $path = $this->getGalleryPath($pathId, $galleryId);
        if (!is_dir($path . '/thumb/')) {
            if (is_dir($path)) {
                rmdir($path);
            }
            return;
        }

        $dir = dir($path . '/thumb/');

        /** @noinspection PhpAssignmentInConditionInspection */
        while (false !== ($entry = $dir->read())) {
            if ($entry == '.' || $entry == '..') {
                continue;
            }

            unlink($path . '/thumb/' . $entry);
            unlink($path . '/high/' . $entry);
        }
        $dir->close();
        if (is_file($path . '/' . self::PREVIEW_FILE)) {
            unlink($path . '/' . self::PREVIEW_FILE);
            unlink($path . '/' . self::PREVIEW_FILE_BIG);
        }

        rmdir($path . '/thumb/');
        rmdir($path . '/high/');
        rmdir($path);
    }

    /**
     * @throws HttpNotFound
     * @throws DatabaseException
     */
    protected function deleteImage(int $pathId, int $galleryId, string $fileName, bool $all = false): void
    {
        $stmt =
            "SELECT `imagegalleries`.`Id` FROM `{TABLE_PREFIX}_gallery_imagegalleries` AS `imagegalleries` " .
            "WHERE `imagegalleries`.`Id` = %s AND `imagegalleries`.`PathId` = %s ";

        #if(!$all) {
        #    $stmt .= "AND `UserId` = '" . $this->database->escape($this->user->getUserId()) . "'";
        #}

        $row = $this->queryHelper->selectRow($stmt, [$galleryId, $pathId]);

        if (empty($row)) {
            throw new HttpNotFound("no entry found for id <$galleryId>");
        }

        $path = $this->getGalleryPath($pathId, $galleryId);

        unlink($path . 'thumb/' . $fileName);
        unlink($path . 'high/' . $fileName);
    }

    /**
     * @throws HttpNotFound
     * @throws Exception
     */
    public function addImage(Request $request, ResponseEngine $responseEngine): Response
    {
        $pathId = $this->getPathId($request);

        $galleryId = (int)filter_input(INPUT_POST, 'gallery', FILTER_VALIDATE_INT);

        $folder = $this->getGalleryPath($pathId, $galleryId);

        $filename = $this->addFile($folder, self::DIMENSIONS);

        $highFolder = $folder . DIRECTORY_SEPARATOR . 'high' . DIRECTORY_SEPARATOR;

        if (!is_file($folder . self::PREVIEW_FILE)) {
            $this->generatePreview($filename, self::DIMENSIONS, $highFolder, $folder . '/' . self::PREVIEW_FILE);
            $this->generatePreview($filename, self::DIMENSIONS_BIG, $highFolder, $folder . '/' . self::PREVIEW_FILE_BIG);
        }

        return $responseEngine->render($request);
    }

    /**
     * @throws \Exception
     */
    protected function generatePreview(string $file, int $dimensions, string $src, string $des): void
    {
        $srcFile = $src . '/' . $file;

        if (!is_file($srcFile)) {
            throw new HttpNotFound("image <$srcFile> not found!");
        }

        [$oldW, $oldH, $srcTyp] = getimagesize($srcFile);


        if ($oldH > $oldW) {
            $newW = $dimensions;
            $newH = ($oldH / $oldW) * $dimensions;
            $limD = $oldW;
        } else {
            $newH = $dimensions;
            $newW = ($oldW / $oldH) * $dimensions;
            $limD = $oldH;
        }

        $new = imagecreatetruecolor($dimensions, $dimensions);

        $old = match ($srcTyp) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($srcFile),
            IMAGETYPE_PNG => imagecreatefrompng($srcFile),
            default => throw new Exception("Invalid Image type <$srcTyp> given"),
        };

        imagecopyresampled($new, $old, 0, 0, (int)(($newW - $dimensions) / 2), (int)(($newH - $dimensions) / 2), $dimensions, $dimensions, $limD, $limD);
        imagepng($new, $des);
        imagedestroy($new);
        imagedestroy($old);
    }

    /**
     * @throws DatabaseException
     */
    protected function getLastModificatonDate(int $pathId)
    {
        $stmt =
            "SELECT `imagegalleries`.`CreationDate` FROM `{TABLE_PREFIX}_gallery_imagegalleries` AS `imagegalleries` " .
            "WHERE `imagegalleries`.`PathId` = %s " .
            "ORDER BY `imagegalleries`.`CreationDate`";

        return $this->queryHelper->selectSingle($stmt, [$pathId]);
    }
}
