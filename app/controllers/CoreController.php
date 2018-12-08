<?php
/**
 * CoreController: Main API controller
 * @author Nicolas Pulido <nicolas.pulido@crazycake.tech>
 */

use \ImageOptimizer\OptimizerFactory as Optimizer;

use CrazyCake\Core\WsCore;
use CrazyCake\Helpers\Images;

class CoreController extends WsCore
{
	use S3Helper;

	/**
	 * Upload directory
	 */
	const UPLOAD_PATH = STORAGE_PATH."uploads/";

	/**
	 * Optimizer options
	 */
	const OPTIMIZER_OPTIONS = [
		"ignore_errors"     => false,
		"jpegoptim_bin"     => "/usr/local/bin/jpegoptim",
		"jpegoptim_options" => ["--strip-all", "--all-progressive"],
		"jpegtran_bin"      => "/usr/bin/jpegtran",
		"jpegtran_options"  => ["-optimize", "-progressive"],
		"pngquant_bin"      => "/usr/bin/pngquant"
	];

	/**
	 * API welcome
	 */
	public function welcome()
	{
		$payload = $this->config->name." API v.".$this->config->version;

		$this->jsonResponse(200, $payload);
	}

	/**
	 * API resize - resize image, optimize, and push to s3
	 */
	public function resize()
	{
		// get post body json data
		$data = $this->request->getJsonRawBody();

		if (!isset($data->contents) || !isset($data->config))
			$this->jsonResponse(400, "Missing contents & config json props.");

		if (empty($data->config->filename) || empty($data->config->resize))
			$this->jsonResponse(400, "Missing filename/resize property config.");

		if (strpos($data->config->filename , "/") !== false)
			$this->jsonResponse(400, "Filename can't contains slashes.");

		$filepath = self::UPLOAD_PATH.$data->config->filename;

		file_put_contents($filepath, base64_decode($data->contents));

		try {

			$this->logger->debug("CoreController::resize-> resizing image [base64 length: ".strlen($data->contents)."]: ".json_encode($data->config->resize));

			// resize images
			$resized = Images::resize($filepath, $data->config->resize);
			// optimize images
			$this->_optimizer($resized);
			// push files to s3
			$response = $this->_pushFiles($filepath, $data->config);
			// clean files
			$this->_cleanFiles(array_merge($resized, [$filepath]));
		}
		catch (\Exception | Exception $e) {

			$response = $e->getMessage();
			$this->logger->error("CoreController::resize -> An error ocurred: $response");
		}

		$this->jsonResponse(200, $response);
	}

	/**
	 * API S3Push - push any file to S3
	 */
	public function s3push()
	{
		// get post body json data
		$data = $this->request->getJsonRawBody();

		if (!isset($data->contents) || !isset($data->config))
			$this->jsonResponse(400, "Missing contents & config json props.");

		if (empty($data->config->filename))
			$this->jsonResponse(400, "Missing filename property config.");

		if (strpos($data->config->filename , "/") !== false)
			$this->jsonResponse(400, "Filename can't contains slashes.");

		$filepath = self::UPLOAD_PATH.$data->config->filename;

		file_put_contents($filepath, base64_decode($data->contents));

		try {

			$this->logger->debug("CoreController::s3push-> pushing image to S3 [base64 length: ".strlen($data->contents)."] ".$data->config->s3->bucketName);

			// push files to s3
			$response = $this->_pushFiles($filepath, $data->config);
			// clean files
			$this->_cleanFiles([$filepath]);
		}
		catch (\Exception | Exception $e) {

			$response = $e->getMessage();
			$this->logger->error("CoreController::s3push -> An error ocurred: $response");
		}

		$this->jsonResponse(200, $response);
	}

	/* --------------------------------------------------- § -------------------------------------------------------- */

	/**
	 * Optimizer task
	 * @param array $files - Input files
	 */
	protected function _optimizer($files = [])
	{
		if (empty($files))
			return false;

		// new optimizer
		$optimizer = (new Optimizer(self::OPTIMIZER_OPTIONS))->get();

		// optimize images
		foreach ($files as $f)
			$optimizer->optimize($f);
	}

	/**
	 * Push files to AWS S3
	 *  @param string $filepath - The main image filepath
	  * @param array $config - s3 config
	 */
	protected function _pushFiles($filepath, $config = [])
	{
		// init helper
		$this->initS3Helper((array)$config->s3);

		return $this->s3PutFiles($filepath, $config->push_source ?? true);
	}

	/**
	 * Remove files
	 */
	protected function _cleanFiles($files = [])
	{
		//if (APP_ENV == "local") return;

		foreach ($files as $f)
			@unlink($f);
	}
}
