<?php

/**
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Ken Chapple <ken@mi-squared.com>
 * @copyright Copyright (c) 2021 Ken Chapple <ken@mi-squared.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU GeneralPublic License 3
 */

namespace OpenEMR\Services\Qdm;

use OpenEMR\Services\CodeTypesService;
use OpenEMR\Services\Qdm\Interfaces\QdmRequestInterface;
use OpenEMR\Services\Qdm\Interfaces\QdmServiceInterface;
use OpenEMR\Services\Qdm\Services\AllergyIntoleranceService;
use OpenEMR\Services\Qdm\Services\AssessmentService;
use OpenEMR\Services\Qdm\Services\ConditionService;
use OpenEMR\Services\Qdm\Services\DiagnosisService;
use OpenEMR\Services\Qdm\Services\DiagnosticStudyOrderedService;
use OpenEMR\Services\Qdm\Services\DiagnosticStudyService;
use OpenEMR\Services\Qdm\Services\EncounterService;
use OpenEMR\Services\Qdm\Services\ImmunizationAdministeredService;
use OpenEMR\Services\Qdm\Services\InterventionOrderedService;
use OpenEMR\Services\Qdm\Services\InterventionService;
use OpenEMR\Services\Qdm\Services\LaboratoryTestOrderedService;
use OpenEMR\Services\Qdm\Services\LaboratoryTestService;
use OpenEMR\Services\Qdm\Services\MedicationActiveService;
use OpenEMR\Services\Qdm\Services\MedicationOrderService;
use OpenEMR\Services\Qdm\Services\PatientService;
use OpenEMR\Services\Qdm\Services\PhysicalExamService;
use OpenEMR\Services\Qdm\Services\ProcedureRecommendedService;
use OpenEMR\Services\Qdm\Services\ProcedureService;
use OpenEMR\Services\Qdm\Services\SubstanceRecommendedService;

class QdmBuilder
{
    protected $services = [
        AllergyIntoleranceService::class,
        AssessmentService::class,
        DiagnosisService::class,
        DiagnosticStudyService::class,
        DiagnosticStudyOrderedService::class,
        EncounterService::class,
        ImmunizationAdministeredService::class,
        InterventionService::class,
        InterventionOrderedService::class,
        LaboratoryTestService::class,
        LaboratoryTestOrderedService::class,
        MedicationActiveService::class,
        MedicationOrderService::class,
        PhysicalExamService::class,
        ProcedureService::class,
        ProcedureRecommendedService::class,
        SubstanceRecommendedService::class,
    ];

    public function build(QdmRequestInterface $request): array
    {
        // Create the patient service
        $patientService = new PatientService($request, new CodeTypesService());

        // Query all patients and build QDM models in a loop, storing the QDM patient model in an associative array,
        // so we can later look up by PID
        $qdm_patients_map = [];
        $patientResult = $patientService->executeQuery();
        while ($patient = sqlFetchArray($patientResult)) {
            $qdmPatient = $patientService->makeQdmModel($patient);
            $qdm_patients_map[$patient['pid']] = $qdmPatient;
        }

        foreach ($this->services as $serviceClass) {
            // Create our service and make sure it implements required methods (inherits from AbstractQdmService).
            $service = new $serviceClass($request, new CodeTypesService());
            if ($service instanceof QdmServiceInterface) {
                // Using the services, query all records for ALL patients in our QdmRequest object, which means we
                // get all relevant models for all patients in one query for this particular service category.
                $result = $service->executeQuery();
                while ($record = sqlFetchArray($result)) {
                    // Create a QDM record with the result. This makes sure we have required PID.
                    if (
                        !isset($record['pid'])
                    ) {
                        throw new \Exception("The query result generated by QdmService::getSqlStatement() must contain a pid");
                    }
                    $qdmRecord = new QdmRecord($record, $record['pid']);
                    // Use the service to make a QDM model with the data from the query result
                    try {
                        $qdmModel = $service->makeQdmModel($qdmRecord->getData());
                        // If for some reason the the model doesn't need to return a value, or the date is invalid, makeQdmModel can return null
                        if ($qdmModel !== null) {
                            // Using the PID map, find the patient this model belongs to and add this data element
                            // to the correct patient's QDM model
                            $qdmPatient = $qdm_patients_map[$qdmRecord->getPid()];
                            $qdmPatient->add_data_element($qdmModel);
                        } else {
                            error_log("QDM Builder Warning: NULL returned by makeQdmModel() on `$serviceClass` for PID = `{$qdmRecord->getPid()}`... Continuing execution.");
                        }
                    } catch (\Exception $e) {
                        // There was an error creating the model, such as passing a parameter that is not a member of a QDM Object
                        // TODO improve error handling
                        error_log($e->getMessage());
                    }
                }
            } else {
                throw new \Exception("Service does not implement required contract for making QDM models");
            }
        }

        // Take just the map of models, re-index into simple array without PID as index
        $models = array_values($qdm_patients_map);
        return $models;
    }
}
