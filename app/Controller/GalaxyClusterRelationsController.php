<?php
App::uses('AppController', 'Controller');

class GalaxyClusterRelationsController extends AppController
{
    public $components = array('Session', 'RequestHandler');

    public $paginate = array(
            'limit' => 60,
            'maxLimit' => 9999, // LATER we will bump here on a problem once we have more than 9999 events <- no we won't, this is the max a user van view/page.
            'recursive' => -1,
    );

    public function index()
    {
        $filters = $this->IndexFilter->harvestParameters(array('context', 'searchall'));
        $aclConditions = $this->GalaxyClusterRelation->buildConditions($this->Auth->user());
        $contextConditions = array();
        if (empty($filters['context'])) {
            $filters['context'] = 'all';
        } else {
            $contextConditions = array();
            if ($filters['context'] == 'default') {
                $contextConditions = array(
                    'GalaxyClusterRelation.default' => true
                );
            } elseif ($filters['context'] == 'custom') {
                $contextConditions = array(
                    'GalaxyClusterRelation.default' => false
                );
            } elseif ($filters['context'] == 'org') {
                $contextConditions = array(
                    'GalaxyClusterRelation.org_id' => $this->Auth->user('org_id')
                );
            }
        }
        $this->set('passedArgsArray', array('context' => $filters['context'], 'searchall' => isset($filters['searchall']) ? $filters['searchall'] : ''));
        $this->set('context', $filters['context']);
        $searchConditions = array();
        if (empty($filters['searchall'])) {
            $filters['searchall'] = '';
        }
        if (strlen($filters['searchall']) > 0) {
            $searchall = '%' . strtolower($filters['searchall']) . '%';
            $searchConditions = array(
                'OR' => array(
                    'LOWER(GalaxyClusterRelation.referenced_galaxy_cluster_type) LIKE' => $searchall,
                    'LOWER(GalaxyCluster.value) LIKE' => $searchall,
                    'LOWER(ReferencedGalaxyCluster.value) LIKE' => $searchall,
                ),
            );
        }

        if ($this->_isRest()) {
            $relations = $this->GalaxyClusterRelation->find('all', 
                array(
                    'recursive' => -1,
                    'conditions' => array(
                        'AND' => array($contextConditions, $searchConditions, $aclConditions)
                    ),
                    'contain' => array('Org', 'Orgc', 'SharingGroup', 'GalaxyCluster', 'ReferencedGalaxyCluster', 'GalaxyClusterRelationTag' => array('Tag'))
                )
            );
            return $this->RestResponse->viewData($relations, $this->response->type());
        } else {
            $this->paginate['conditions']['AND'][] = $contextConditions;
            $this->paginate['conditions']['AND'][] = $searchConditions;
            $this->paginate['conditions']['AND'][] = $aclConditions;
            $this->paginate['contain'] = array('Org', 'Orgc', 'SharingGroup', 'GalaxyCluster', 'ReferencedGalaxyCluster', 'GalaxyClusterRelationTag' => array('Tag'));
            $relations = $this->paginate();
            $this->loadModel('SharingGroup');
            $sgs = $this->SharingGroup->fetchAllAuthorised($this->Auth->user());
            $this->loadModel('Attribute');
            $distributionLevels = $this->Attribute->distributionLevels;
            unset($distributionLevels[5]);
            $this->set('distributionLevels', $distributionLevels);
            $this->set('data', $relations);
        }
    }

    public function add()
    {
        $this->loadModel('Attribute');
        $distributionLevels = $this->Attribute->distributionLevels;
        unset($distributionLevels[5]);
        $initialDistribution = 3;
        $configuredDistribution = Configure::check('MISP.default_attribute_distribution');
        if ($configuredDistribution != null && $configuredDistribution != 'event') {
            $initialDistribution = $configuredDistribution;
        }
        $this->loadModel('SharingGroup');
        $sgs = $this->SharingGroup->fetchAllAuthorised($this->Auth->user(), 'name', 1);

        if ($this->request->is('post')) {
            if (empty($this->request->data['GalaxyClusterRelation'])) {
                $this->request->data = array('GalaxyClusterRelation' => $this->request->data);
            }
            $relation = $this->request->data;
            if ($relation['GalaxyClusterRelation']['distribution'] != 4) {
                $relation['GalaxyClusterRelation']['sharing_group_id'] = null;
            }

            // Fetch cluster source and adapt IDs
            $conditions = array();
            if (!is_numeric($relation['GalaxyClusterRelation']['source_id'])) {
                $conditions['GalaxyCluster.uuid'] = $relation['GalaxyClusterRelation']['source_id'];
            } else {
                $conditions['GalaxyCluster.id'] = $relation['GalaxyClusterRelation']['source_id'];
            }
            $clusterSource = $this->GalaxyClusterRelation->GalaxyCluster->fetchGalaxyClusters($this->Auth->user(), array('conditions' => $conditions), false);
            if (empty($clusterSource)) {
                throw new NotFoundException('Source cluster not found.');
            }
            $clusterSource = $clusterSource[0];
            $relation['GalaxyClusterRelation']['galaxy_cluster_id'] = $clusterSource['GalaxyCluster']['id'];
            unset($relation['GalaxyClusterRelation']['source_id']);

            // Fetch cluster target and adapt IDs
            $conditions = array();
            if (!is_numeric($relation['GalaxyClusterRelation']['target_id'])) {
                $conditions['GalaxyCluster.uuid'] = $relation['GalaxyClusterRelation']['target_id'];
            } else {
                $conditions['GalaxyCluster.id'] = $relation['GalaxyClusterRelation']['target_id'];
            }
            $clusterTarget = $this->GalaxyClusterRelation->GalaxyCluster->fetchGalaxyClusters($this->Auth->user(), array('conditions' => $conditions), false);
            if (empty($clusterSource)) {
                throw new NotFoundException('Target cluster not found.');
            }
            $clusterTarget = $clusterTarget[0];
            $relation['GalaxyClusterRelation']['referenced_galaxy_cluster_id'] = $clusterTarget['GalaxyCluster']['id'];
            unset($relation['GalaxyClusterRelation']['target_id']);

            $saveSuccess = $this->GalaxyClusterRelation->saveRelation($this->Auth->user(), $relation);
            $message = $saveSuccess ? __('Relationship added.') : __('Relationship could not be added.');
            if ($this->_isRest()) {
                if ($result) {
                    return $this->RestResponse->saveSuccessResponse('GalaxyClusterRelation', 'add', $this->response->type(), $message);
                } else {
                    return $this->RestResponse->saveFailResponse('GalaxyClusterRelation', 'add', $message, $this->response->type());
                }
            } else {
                if ($saveSuccess) {
                    $this->Flash->success($message);
                    $this->redirect(array('action' => 'index'));
                } else {
                    $message .= __(' Reason: %s', json_encode($this->GalaxyClusterRelation->validationErrors, true));
                    $this->Flash->error($message);
                }
            }
        }
        $this->set('distributionLevels', $distributionLevels);
        $this->set('initialDistribution', $initialDistribution);
        $this->set('sharingGroups', $sgs);
        $this->set('action', 'add');
    }

    public function edit($id)
    {
        $conditions = array('conditions' => array('GalaxyClusterRelation.id' => $id));
        $existingRelation = $this->GalaxyClusterRelation->fetchRelations($this->Auth->user(), $conditions);
        if (empty($existingRelation)) {
            throw new NotFoundException(__('Invalid cluster relation'));
        }
        $existingRelation = $existingRelation[0];
        $id = $existingRelation['GalaxyClusterRelation']['id'];
        if ($existingRelation['GalaxyClusterRelation']['default']) {
            throw new MethodNotAllowedException(__('Default cluster relation cannot be edited'));
        }

        $this->loadModel('Attribute');
        $distributionLevels = $this->Attribute->distributionLevels;
        unset($distributionLevels[5]);
        $initialDistribution = 3;
        $configuredDistribution = Configure::check('MISP.default_attribute_distribution');
        if ($configuredDistribution != null && $configuredDistribution != 'event') {
            $initialDistribution = $configuredDistribution;
        }
        $this->loadModel('SharingGroup');
        $sgs = $this->SharingGroup->fetchAllAuthorised($this->Auth->user(), 'name', 1);

        if ($this->request->is('post') || $this->request->is('put')) {
            $relation = $this->request->data;
            $relation['GalaxyClusterRelation']['id'] = $id;
            $errors = $this->GalaxyClusterRelation->editRelation($this->Auth->user(), $relation);
            $message = empty($errors) ? __('Relationship saved.') : __('Relationship could not be edited.');
            if ($this->_isRest()) {
                if (empty($errors)) {
                    return $this->RestResponse->saveSuccessResponse('GalaxyClusterRelation', 'edit', $this->response->type(), $message);
                } else {
                    return $this->RestResponse->saveFailResponse('GalaxyClusterRelation', 'edit', $message, $this->response->type());
                }
            } else {
                if (empty($errors)) {
                    $this->Flash->success($message);
                    $this->redirect(array('action' => 'index'));
                } else {
                    $message .= __(' Reason: %s', json_encode($this->GalaxyClusterRelation->validationErrors, true));
                    $this->Flash->error($message);
                }
            }
        }
        $this->request->data = $existingRelation;
        $this->request->data['GalaxyClusterRelation']['source_id'] = $existingRelation['GalaxyClusterRelation']['galaxy_cluster_id'];
        $this->request->data['GalaxyClusterRelation']['target_id'] = $existingRelation['GalaxyClusterRelation']['referenced_galaxy_cluster_id'];
        $this->set('distributionLevels', $distributionLevels);
        $this->set('initialDistribution', $initialDistribution);
        $this->set('sharingGroups', $sgs);
        $this->set('action', 'edit');
        $this->render('add');
    }

    public function delete($id)
    {
        if ($this->request->is('post')) {
            $relation = $this->GalaxyClusterRelation->fetchRelations($this->Auth->user(), array('conditions' => array('id' => $id)));
            if (empty($relation)) {
                throw new NotFoundException('Target cluster not found.');
            }
            $result = $this->GalaxyCluster->delete($id, true);
            if ($result) {
                $message = 'Galaxy cluster relationship successfuly deleted.';
                if ($this->_isRest()) {
                    return $this->RestResponse->saveSuccessResponse('GalaxyClusterRelation', 'delete', $id, $this->response->type());
                } else {
                    $this->Flash->success($message);
                    $this->redirect($this->here);
                }
            } else {
                $message = 'Galaxy cluster relationship could not be deleted.';
                if ($this->_isRest()) {
                    return $this->RestResponse->saveFailResponse('GalaxyClusterRelation', 'delete', $id, $message, $this->response->type());
                } else {
                    $this->Flash->error($message);
                    $this->redirect($this->here);
                }
            }
        }
    }
}