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

class DiffusionChangeController extends DiffusionController {

  public function processRequest() {
    $drequest = $this->diffusionRequest;

    $content = array();

    $diff_query = DiffusionDiffQuery::newFromDiffusionRequest($drequest);
    $changeset = $diff_query->loadChangeset();

    if (!$changeset) {
      // TODO: Refine this.
      return new Aphront404Response();
    }

    $changeset_view = new DifferentialChangesetListView();
    $changeset_view->setChangesets(array($changeset));
    $changeset_view->setRenderURI(
      '/diffusion/'.$drequest->getRepository()->getCallsign().'/diff/');

    $content[] = $this->buildCrumbs(
      array(
        'branch' => true,
        'path'   => true,
        'view'   => 'change',
      ));

    // TODO: This is pretty awkward, unify the CSS between Diffusion and
    // Differential better.
    require_celerity_resource('differential-core-view-css');
    $content[] =
      '<div class="differential-primary-pane">'.
        $changeset_view->render().
      '</div>';

    $nav = $this->buildSideNav('change', true);
    $nav->appendChild($content);

    return $this->buildStandardPageResponse(
      $nav,
      array(
        'title' => 'Change',
      ));
  }

}
