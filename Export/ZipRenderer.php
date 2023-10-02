<?php

namespace KimaiPlugin\ZipProjectRendererBundle\Export;

use App\Export\Base\RendererTrait;
use App\Export\RendererInterface;
use App\Repository\Query\TimesheetQuery;
use Symfony\Component\HttpFoundation\Response;
use App\Pdf\PdfContext;
use App\Project\ProjectStatisticService;
use App\Utils\FileHelper;
use App\Pdf\HtmlToPdfConverter;
use Twig\Environment;
use PhpOffice\PhpWord\Shared\ZipArchive;

final class ZipRenderer implements RendererInterface
{
	use RendererTrait;
	
	/**
     * @var Environment
     */
    private $twig;
    /**
     * @var HtmlToPdfConverter
     */
    private $converter;
    /**
     * @var ProjectStatisticService
     */
    private $projectStatisticService;
    /**
     * @var string
     */
    private $template = 'default.zip.twig';
	/**
     * @var string
     */
    private $id = 'zip';
	/**
     * @var array
     */
    private $pdfOptions = [];
	
	public function __construct(Environment $twig, HtmlToPdfConverter $converter, ProjectStatisticService $projectRepository)
    {
        $this->twig = $twig;
        $this->converter = $converter;
        $this->projectStatisticService = $projectRepository;
    }
	
	public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getId(): string
    {
        return $this->id;
    }
	
	protected function getTemplate(): string
    {
        return '@export/' . $this->template;
    }
	
	public function setTemplate(string $filename): void
    {
        $this->template = $filename;
    }
	
	
	public function getPdfOptions(): array
    {
        return $this->pdfOptions;
    }
	
	public function setPdfOption(string $key, string $value): void
    {
        $this->pdfOptions[$key] = $value;
    }

	protected function getOptions(TimesheetQuery $query): array
    {
        $decimal = false;
        if (null !== $query->getCurrentUser()) {
            $decimal = $query->getCurrentUser()->isExportDecimal();
        } elseif (null !== $query->getUser()) {
            $decimal = $query->getUser()->isExportDecimal();
        }

        return ['decimal' => $decimal];
    }
	
	
	
	public function getIcon(): string
    {
        return 'zip';
    }

    public function getTitle(): string
    {
        return 'zip';
    }
	
	private function replaceBadFilenameChars($filename): string {
	  $patterns = array(
		"/\\s/",  # Leerzeichen
		"/\\&/",  # Kaufmaennisches UND
		"/\\+/",  # Plus-Zeichen
		"/\\</",  # < Zeichen
		"/\\>/",  # > Zeichen
		"/\\?/",  # ? Zeichen
		"/\"/",   # " Zeichen
		"/\\:/",  # : Zeichen
		"/\\|/",  # | Zeichen
		"/\\\\/", # \ Zeichen
		"/\\//",  # / Zeichen
		"/\\*/"   # * Zeichen
	  );
	  
	  $replacements = array(
		"_",
		"-",
		"-",
		"",
		"",
		"",
		"",
		"",
		"",
		"",
		"",
		""
	  );
	  
	  return preg_replace( $patterns, $replacements, $filename ); 
	}
	

	public function render(array $timesheets, TimesheetQuery $query): Response
    {
        $context = new PdfContext();
        $context->setOption('filename', 'kimai-export');

			
		$projects = array();
		
		$tempDir =  sys_get_temp_dir() . "/";

		$zip_name = $tempDir . "export.zip";
		$zip = new ZipArchive();
		$status = $zip->open($zip_name, ZipArchive::CREATE);

		foreach($timesheets as $key=>$value) {
			if (!isset($projects[$value->getProject()->getId()])) {
				$projects[$value->getProject()->getId()] = array();
			}
			
			if (!isset(${$value->getProject()->getId()})) {
				${$value->getProject()->getId()} = array();
			}
			${$value->getProject()->getId()} = array_merge(${$value->getProject()->getId()}, array($value));
		}
		
		foreach($projects as $key=>$value) {
			
			foreach(${$key} as $mysheet) {
				$summary = $this->calculateSummary(${$key});
				$content = $this->twig->render($this->getTemplate(), array_merge([
					'entries' => ${$key},
					'query' => $query,
					// @deprecated since 1.13
					'now' => new \DateTime('now', new \DateTimeZone(date_default_timezone_get())),
					'summaries' => $summary,
					'budgets' => $this->calculateProjectBudget($timesheets, $query, $this->projectStatisticService),
					'decimal' => false,
					'pdfContext' => $context
				], $this->getOptions($query)));


				$pdfOptions = array_merge($context->getOptions(), $this->getPdfOptions());

				$content = $this->converter->convertToPdf($content, $pdfOptions);

				$pdfDateiName = $this->replaceBadFilenameChars($mysheet->getProject()->getCustomer() . "_" .$mysheet->getProject()->getName() . ".pdf");
				file_put_contents($tempDir . $pdfDateiName , $content);
				$zip->addFile($tempDir . $pdfDateiName, $pdfDateiName);				
			}
		}
		$zip->close();

		foreach($projects as $key=>$value) {
			unlink($tempDir . $this->replaceBadFilenameChars(${$key}[0]->getProject()->getCustomer() . "_" .${$key}[0]->getProject()->getName() . ".pdf"));
		}

        $response = new Response(file_get_contents($zip_name));

        $filename = $context->getOption('filename');
        if (empty($filename)) {
            $filename = 'kimai-export';
        }

        $filename = FileHelper::convertToAsciiFilename($filename);
		
		$response->headers->set('Content-Type', 'application/zip');
		$response->headers->set('Content-Disposition', 'attachment;filename="' . $filename . '.zip"');
		$response->headers->set('Content-length', filesize($zip_name));
		
		unlink($zip_name);
		
        return $response;
    }
}
