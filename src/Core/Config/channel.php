<?php
forex_providers:
  absa:
    enabled: true
    base_url: "https://gateway.bifrost.cib-absaaccess.prod.caas.absa.co.za/fxratesapi/1.0"
    identifier: "${ABSA_FX_IDENTIFIER}"
    identifier_type: "ClientSdsId"
    country_codes:
      ZAR: "ZA"
      BWP: "BW"
      USD: "US"
    # Use for swaps where currency pair involves ZAR, BWP, USD
    supported_pairs: ["USDZAR", "GBPZAR", "EURUSD", "EURZAR", "BWPUSD"]
