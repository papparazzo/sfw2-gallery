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
use SFW2\Core\HttpExceptions\HttpInternalServerError;
use SFW2\Core\HttpExceptions\HttpNotFound;
use SFW2\Core\HttpExceptions\HttpUnprocessableContent;
use SFW2\Core\Utils\DateTimeHelper;
use SFW2\Core\Permission\AccessType;
use SFW2\Core\Permission\PermissionInterface;

use SFW2\Database\DatabaseInterface;
use SFW2\Gallery\Helper\ImageHelperTrait;
use SFW2\Routing\AbstractController;

use SFW2\Routing\HelperTraits\getRoutingDataTrait;
use SFW2\Routing\ResponseEngine;
use SFW2\Validator\Enumerations\DateCompareEnum;
use SFW2\Validator\Ruleset;
use SFW2\Validator\Validator;
use SFW2\Validator\Validators\IsNotEmpty;
use SFW2\Validator\Validators\IsDate;

final class Newspaper extends AbstractController {

    use getRoutingDataTrait;
    use ImageHelperTrait;

    const DIMENSIONS = 600;

    protected string $title;
    protected string $about;

    public function __construct(
        protected DatabaseInterface $database,
        protected DateTimeHelper $dateTimeHelper,
        private readonly PermissionInterface $permission
    ) {
    }

    /**
     * @throws HttpNotFound
     * @throws Exception
     */
    public function index(Request $request, ResponseEngine $responseEngine): Response
    {
        $pathId = $this->getPathId($request);

        $stmt = /** @lang MySQL */
            "SELECT `Id`, `Title`, `Date`, `Source`, `FileName` " .
            "FROM `{TABLE_PREFIX}_gallery_newspaperarticles` AS `article` " .
            "WHERE `article`.`PathId` = %s " .
            "ORDER BY `article`.`Date` DESC";
        $rows = $this->database->select($stmt, [$pathId]);

        $entries = [];

        foreach($rows as $row) {
            $entry = [];
            $entry['id'     ] = $row['Id'];
            $entry['date'   ] = $this->getDate($row['Date']);
            $entry['title'  ] = $row['Title'];
            $entry['image'  ] = $this->getImageFile($row['FileName'], $pathId, false);
            $entry['preview'] = $this->getImageFile($row['FileName'], $pathId, true);
            $entry['source' ] = $row['Source'];

            $entries[] = $entry;
        }

        $content = [
            'title'          => $this->title,
            'about'          => $this->about,
            'create_allowed' => $this->permission->checkPermission($pathId, 'create') !== AccessType::VORBIDDEN,
            'delete_allowed' => $this->permission->checkPermission($pathId, 'delete') !== AccessType::VORBIDDEN,
            'items'          => $entries
        ];

        /*
        $content->assign('webmaster', (string)(new EMail($this->config->getVal('project', 'eMailWebMaster'))));
        */

        return $responseEngine->render($request, $content, "SFW2\\Gallery\\Newspaper");
    }

    /**
     * @noinspection PhpMissingParentCallCommonInspection
     * @throws HttpUnprocessableContent
     * @throws HttpNotFound
     * @throws HttpInternalServerError
     * @throws HttpBadRequest
     * @throws DatabaseException
     */
    public function delete(Request $request, ResponseEngine $responseEngine): Response
    {
        $entryId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if($entryId === false) {
            throw new HttpUnprocessableContent("invalid data given");
        }

        $stmt = "SELECT `FileName` FROM `{TABLE_PREFIX}_gallery_newspaperarticles` ";
        $where = "WHERE `Id` = %s AND `PathId` = %s ";
        /*
        * if(!$all) {
        * $where .= "AND `UserId` = '" . $this->database->escape($this->user->getUserId()) . "'";
        * }
        */
        $pathId = $this->getPathId($request);

        $queryHelper = new QueryHelper($this->database);
        $row = $queryHelper->selectRow($stmt . $where, [$entryId, $pathId]);

        if(empty($row)) {
            throw new HttpBadRequest("no entry <$entryId> found");
        }

        $preview = $this->getImageFile($row['FileName'], $pathId, true);
        if(!unlink(ltrim($preview, DIRECTORY_SEPARATOR))) {
            throw new HttpInternalServerError("unable to delete file <$preview>");
        }

        $hight = $this->getImageFile($row['FileName'], $pathId, false);
        if(!unlink(ltrim($hight, DIRECTORY_SEPARATOR))) {
            throw new HttpInternalServerError("unable to delete file <$hight>");
        }

        $stmt = "DELETE FROM `{TABLE_PREFIX}_gallery_newspaperarticles` " . $where;
        $this->database->delete($stmt, [$entryId, $pathId]);
        return $responseEngine->render($request);
    }

    /**
     * @noinspection PhpMissingParentCallCommonInspection
     * @throws HttpNotFound
     * @throws Exception
     */
    public function create(Request $request, ResponseEngine $responseEngine): Response
    {
        $validateOnly = filter_input(INPUT_POST, 'validateOnly', FILTER_VALIDATE_BOOLEAN);

        $rulset = new Ruleset();
        $rulset->addNewRules('title', new IsNotEmpty());
        $rulset->addNewRules('date', new IsNotEmpty(), new IsDate(DateCompareEnum::PAST_DATE));
        $rulset->addNewRules('source', new IsNotEmpty());

        $validator = new Validator($rulset);
        $values = [];

        $error = $validator->validate($_POST, $values);

        if(!$error) {
            return
                $responseEngine->
                render($request, ['sfw2_payload' => $values])->
                withStatus(StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY);
        }

        if($validateOnly) {
            return $responseEngine->render($request, ['sfw2_payload' => $values]);
        }

        $pathId = $this->getPathId($request);
        $folder = $this->getGalleryPath($pathId);
        $fileName = $this->addFile($folder, self::DIMENSIONS);

        $date = $values['date']['value'];
        $title = $values['title']['value'];
        $source = $values['source']['value'];

        $stmt =
            "INSERT INTO `{TABLE_PREFIX}_gallery_newspaperarticles` " .
            "SET `Date` = %s, " .
            "`Title` = %s, " .
            "`Source` = %s, " .
            "`UserId` = %s, " .
            "`PathId` = %s, " .
            "`FileName` = %s";

        $this->database->insert(
            $stmt,
            [
                $date, $title, $source, $this->user->getUserId(), $pathId, $fileName
            ]
        );
        return $responseEngine->render($request);
    }

    /**
     * @throws Exception
     */
    private function getDate(?string $date): string
    {
        if(is_null($date)) {
            return "unbekannt";
        }
        return $this->dateTimeHelper->getDate(DateTimeHelper::FULL_DATE, $date);
    }
}
