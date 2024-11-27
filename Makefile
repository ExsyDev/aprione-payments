MODULE_NAME = apirone_payments
ZIP_FILE = $(MODULE_NAME).zip

zip:
	zip -r $(ZIP_FILE) ./ -x "*.DS_Store" -x "__MACOSX"
