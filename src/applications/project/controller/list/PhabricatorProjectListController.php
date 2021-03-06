<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

class PhabricatorProjectListController
  extends PhabricatorProjectController {

  public function processRequest() {

    $projects = id(new PhabricatorProject())->loadAllWhere(
      '1 = 1 ORDER BY id DESC limit 100');

    $rows = array();
    foreach ($projects as $project) {
      $rows[] = array(
        phutil_escape_html($project->getName()),
        phutil_render_tag(
          'a',
          array(
            'class' => 'small grey button',
            'href' => '/project/view/'.$project->getID().'/',
          ),
          'View Project Project Profile'),
      );
    }

    $table = new AphrontTableView($rows);
    $table->setHeaders(
      array(
        'Project',
        '',
      ));
    $table->setColumnClasses(
      array(
        'wide',
        'action',
      ));

    $panel = new AphrontPanelView();
    $panel->appendChild($table);
    $panel->setHeader('People');
    $panel->setCreateButton('Create New Project Project', '/project/edit/');

    return $this->buildStandardPageResponse(
      $panel,
      array(
        'title' => 'Project Projects',
      ));
  }

}
