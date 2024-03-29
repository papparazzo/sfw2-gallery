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
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SFW2\Core\HttpExceptions\HttpInternalServerError;
use SFW2\Core\HttpExceptions\HttpNotFound;
use SFW2\Core\HttpExceptions\HttpUnprocessableContent;
use SFW2\Database\Database;
use SFW2\Database\DatabaseException;
use SFW2\Gallery\Helper\ImageHelperTrait;
use SFW2\Routing\AbstractController;
use SFW2\Controllers\Widget\Obfuscator\EMail;
use SFW2\Authority\User;
use SFW2\Routing\HelperTraits\getRoutingDataTrait;
use SFW2\Routing\ResponseEngine;
use SFW2\Routing\Result\Content;

use SFW2\Routing\Resolver\ResolverException;

use SFW2\Core\Config;

use SFW2\Validator\Ruleset;
use SFW2\Validator\Validator;
use SFW2\Validator\Validators\IsNotEmpty;

class Overview extends AbstractController {

    use getRoutingDataTrait;
    use ImageHelperTrait;

    const SUMMERIES_PER_PAGE = 3;
    const DIMENSIONS = 300;

    protected string $title;
    protected string $about;

    public function __construct(
        private readonly Database $database/*, string $title = '', string $about = ''*/
    )
    {
      #  $this->title = $title;
      #  $this->about = $about;
    }

    public function index(Request $request, ResponseEngine $responseEngine): Response
    {
        $content = [
            'title' => $this->title,
            'about' =>$this->about,
            'modificationDate' =>$this->getLastModificatonDate(),
            #'webmaster' =>(string)(new EMail($this->config->getVal('project', 'eMailWebMaster'))),
        ];

        #$content->appendJSFile('Overview.handlebars.js');
        #$content->appendCSSFile('lightbox.min.css');
        #$content->appendJSFile('lightbox.min.js');

        return $responseEngine->render($request, $content, "SFW2\\Gallery\\Overview");

    }

    public function read(Request $request, ResponseEngine $responseEngine): Response
    {
        $count = (int)filter_input(INPUT_GET, 'count', FILTER_VALIDATE_INT);
        $start = (int)filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT);

        $count = $count ?: self::SUMMERIES_PER_PAGE;

        $stmt = /** @lang MySQL */
            "SELECT `overview`.`Id`, `overview`.`Title`, `overview`.`Description`, `overview`.`Date`, " .
            "`overview`.`FileName`, " .
            "IF(`overview`.`UserId` = %s, '1', '0') AS `OwnEntry`, `overview`.`Names` " .
            "FROM `{TABLE_PREFIX}_gallery_overview` AS `overview` " .
            "WHERE `overview`.`PathId` = %s ";

        if($all) {
            $stmt .=
                "ORDER BY `overview`.`Pos` DESC " .
                "LIMIT %s, %s ";
            $rows = $this->database->select($stmt, [$this->user->getUserId(), $this->pathId, $start, $count]);
            $cnt = $this->database->selectCount('{TABLE_PREFIX}_overview', "WHERE `PathId` = %s", [$this->pathId]);
        } else {
            $stmt .=
                "AND `UserId` = %s " .
                "ORDER BY `overview`.`Pos` DESC " .
                "LIMIT %s, %s ";
            $rows = $this->database->select($stmt, [$this->user->getUserId(), $this->pathId, $this->user->getUserId(), $start, $count]);
            $cnt = $this->database->selectCount('{TABLE_PREFIX}_overview', "WHERE `PathId` = %s AND `UserId` = %s", [$this->pathId, $this->user->getUserId()]);
        }

        $entries = [];

        foreach($rows as $row) {
            $cd = $this->getDate($row['Date']);
            $entry = [];
            $entry['id'         ] = $row['Id'];
            $entry['date'       ] = $cd;
            $entry['title'      ] = $row['Title'];
            $entry['image'      ] = $this->getImageFile($row['FileName'], false);
            $entry['preview'    ] = $this->getImageFile($row['FileName'], true);
            $entry['description'] = $row['Description'];
            $entry['names'      ] = $row['Names'];
            $entry['ownEntry'   ] = (bool)$row['OwnEntry'];

            $entries[] = $entry;
        }
        $content = [
            'offset' => $start + $count,
            'hasNext' =>  $start + $count < $cnt,
            'entries' => $entries,

        ];
        return $responseEngine->render($request, $content, "SFW2\\Gallery\\Overview");
    }

    /**
     * @throws HttpUnprocessableContent
     * @throws DatabaseException
     * @throws HttpNotFound
     * @throws HttpInternalServerError
     */
    public function delete(Request $request, ResponseEngine $responseEngine): Response
    {
        $entryId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if($entryId === false) {
            throw new HttpUnprocessableContent("invalid data given");
        }

        $pathId = $this->getPathId($request);

        $stmt = "SELECT `FileName` FROM `{TABLE_PREFIX}_overview` ";
        $where = "WHERE `Id` = %s AND `PathId` = %s ";

        #if(!$all) {
        #    $where .= "AND `UserId` = '" . $this->database->escape($this->user->getUserId()) . "'";
        #}

        $row = $this->database->selectRow($stmt . $where, [$entryId, $pathId]);

        if(empty($row)) {
            throw new HttpNotFound("no entry <$entryId> found");
        }

        $preview = $this->getImageFile($row['FileName'], $pathId, true);
        if(!unlink(ltrim($preview, DIRECTORY_SEPARATOR))) {
            throw new HttpInternalServerError("unable to delete file <$preview>");
        }

        $hight = $this->getImageFile($row['FileName'], $pathId, false);
        if(!unlink(ltrim($hight, DIRECTORY_SEPARATOR))) {
            throw new HttpInternalServerError("unable to delete file <$hight>");
        }

        $stmt = "DELETE FROM `{TABLE_PREFIX}_newspaperarticles` " . $where;
        $this->database->delete($stmt, [$entryId, $pathId]);
         return $responseEngine->render($request);
    }

    /**
     * @throws HttpNotFound
     * @throws Exception
     */
    public function create(Request $request, ResponseEngine $responseEngine): Response
    {
        $content = new Content('Overview');

        $pathId = $this->getPathId($request);

        $validateOnly = filter_input(INPUT_POST, 'validateOnly', FILTER_VALIDATE_BOOLEAN);

        $rulset = new Ruleset();
        $rulset->addNewRules('title', new IsNotEmpty());
        $rulset->addNewRules('description', new IsNotEmpty());
        $rulset->addNewRules('names', new IsNotEmpty());

        $validator = new Validator($rulset);
        $values = [];

        $error = $validator->validate($_POST, $values);
        $content->assignArray($values);

        if(!$error) {
            $content->setError(true);
        }

        if($validateOnly || !$error) {
            return $content;
        }

        $folder = $this->getGalleryPath($pathId);
        $fileName = $this->addFile($folder, self::DIMENSIONS);

        $stmt =
            "INSERT INTO `{TABLE_PREFIX}_overview` " .
            "SET `Date` = NOW(), " .
            "`Title` = %s, " .
            "`Pos` = '0', " .
            "`UserId` = %s, " .
            "`PathId` = %s, ".
            "`Description` = %s, " .
            "`Names` = %s, " .
            "`FileName` = %s ";

        $id = $this->database->insert(
            $stmt,
            [
                $values['title']['value'],
                $this->user->getUserId(),
                $pathId,
                $values['description']['value'],
                $values['names']['value'],
                $fileName
            ]
        );
                return $responseEngine->render($request, $content, "SFW2\\Gallery\\Overview");
    }

    protected function getLastModificatonDate(): string {
        $stmt = /** @lang MySQL */
            "SELECT `overview`.`Date` " .
            "FROM `{TABLE_PREFIX}_overview` AS `overview` " .
            "WHERE `overview`.`PathId` = %s " .
            "ORDER BY `overview`.`Date`";

        return (string)$this->database->selectSingle($stmt, [$this->pathId]);
    }

}
