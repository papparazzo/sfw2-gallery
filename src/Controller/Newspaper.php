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
use IntlDateFormatter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SFW2\Database\DatabaseInterface;
use SFW2\Routing\AbstractController;

use SFW2\Routing\HelperTraits\getPathTrait;
use SFW2\Routing\ResponseEngine;
use SFW2\Validator\Ruleset;
use SFW2\Validator\Validator;
use SFW2\Validator\Validators\IsNotEmpty;
use SFW2\Validator\Validators\IsDate;

use SFW2\Gallery\Helper\GalleryHelperTrait;

class Newspaper extends AbstractController {

    use getPathTrait;
    use GalleryHelperTrait;

    const SUMMERIES_PER_PAGE = 3;
    const DIMENSIONS = 600;

    protected Config $config;
    protected User $user;
    protected string $title;
    protected string $about;

    public function __construct(
        protected DatabaseInterface $database
    ) {
    }

    public function index(Request $request, ResponseEngine $responseEngine): Response
    {
        $pathId = $this->getPathId($request);

        $stmt =
            "SELECT `Id`, `Title`, `Date`, `Source`, `FileName` " .
            "FROM `{TABLE_PREFIX}_newspaperarticles` AS `article` " .
            "WHERE `article`.`PathId` = %s " .
            "ORDER BY `article`.`Date` DESC";
        $rows = $this->database->select($stmt, [$pathId]);

        $entries = [];

        foreach($rows as $row) {
            $cd = $this->getShortDate($row['Date']);
            $entry = [];
            $entry['id'         ] = $row['Id'];
            $entry['date'       ] = $cd;
            $entry['title'      ] = $row['Title'];
            $entry['image'      ] = $this->getImageFile($row['FileName'], $pathId, false);
            $entry['preview'    ] = $this->getImageFile($row['FileName'], $pathId, true);

            $entry['source'     ] = $row['Source'];

            $entries[] = $entry;
        }

        $content = [
            'title' => 'Pressemitteilungen', // FIXME: outsource this!
            'about' => 'Hier ein paar lesenswerte Zeitungsartikel über die Springer Singgemeinschaft',
            'items' => $entries
        ];

        /*
        $content->assign('webmaster', (string)(new EMail($this->config->getVal('project', 'eMailWebMaster'))));

        $content->appendJSFile('Newspaper.handlebars.js');
        $content->appendCSSFile('lightbox.min.css');
        $content->appendJSFile('lightbox.min.js');
        */

        return $responseEngine->render(
            $request,
            $content,
            "SFW2\\Gallery\\Newspaper"
        );
    }

    /*
    public function read(bool $all = false) : Content {
    }

    /**
     * @throws \SFW2\Routing\Resolver\ResolverException
     * /
    public function delete(Request $request, ResponseEngine $responseEngine): Response
    {
        $entryId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if($entryId === false) {
            throw new ResolverException("invalid data given", ResolverException::INVALID_DATA_GIVEN);
        }

        $stmt = "SELECT `FileName` FROM `{TABLE_PREFIX}_newspaperarticles` ";
        $where = "WHERE `Id` = '%s' AND `PathId` = '%s' ";

        if(!$all) {
            $where .= "AND `UserId` = '" . $this->database->escape($this->user->getUserId()) . "'";
        }

        $row = $this->database->selectRow($stmt . $where, [$entryId, $this->pathId]);

        if(empty($row)) {
            throw new ResolverException("no entry <$entryId> found", ResolverException::NO_PERMISSION);
        }

        $preview = $this->getImageFile($row['FileName'], true);
        if(!unlink(ltrim($preview, DIRECTORY_SEPARATOR))) {
            throw new ResolverException("unable to delete file <$preview>", ResolverException::UNKNOWN_ERROR);
        }

        $hight = $this->getImageFile($row['FileName'], false);
        if(!unlink(ltrim($hight, DIRECTORY_SEPARATOR))) {
            throw new ResolverException("unable to delete file <$hight>", ResolverException::UNKNOWN_ERROR);
        }

        $stmt = "DELETE FROM `{TABLE_PREFIX}_newspaperarticles` " . $where;
        $this->database->delete($stmt, [$entryId, $this->pathId]);
        return new Content();
    }

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
            return
                $responseEngine->
                render($request, ['sfw2_payload' => $values]);
        }

        $pathId = $this->getPathId($request);
        $folder = $this->getGalleryPath($pathId);
        $fileName = $this->addFile($folder, self::DIMENSIONS);

        $date = $values['date']['value'];
        $title = $values['title']['value'];
        $source = $values['source']['value'];

        $stmt =
            "INSERT INTO `{TABLE_PREFIX}_newspaperarticles` " .
            "SET `Date` = '%s', " .
            "`Title` = '%s', " .
            "`Source` = '%s', " .
            "`UserId` = '%s', " .
            "`PathId` = '%s', " .
            "`FileName` = '%s'";

        $this->database->insert(
            $stmt,
            [
                $date, $title, $source, $this->user->getUserId(), $pathId, $fileName
            ]
        );
        return $responseEngine->render($request);
    }

    protected function getLastModificatonDate() : string {
        $stmt =
            "SELECT `newspaperarticles`.`Date` FROM `{TABLE_PREFIX}_newspaperarticles` AS `newspaperarticles` " .
            "WHERE `newspaperarticles`.`PathId` = '%s' " .
            "ORDER BY `newspaperarticles`.`Date`";

        return (string)$this->database->selectSingle($stmt, [$this->pathId]);
    }*/

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
