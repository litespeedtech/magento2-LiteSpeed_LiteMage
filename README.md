# magento2-LiteSpeed_LiteMage

## Description

The LiteMage Cache module provides an improved caching solution alternative to the PageCache module and Varnish Cache. The module will replace the Varnish option to the cache selector in the administrator interface for easy switching. 

For most use cases, LiteMage Cache can improve your stores' performance right out of the box. LiteMage Cache also reduces the complexity of your stack; only the LiteMage Extension and LiteSpeed Web Server are required\*. There is no need for an NGINX reverse proxy nor a Varnish Cache instance because the server can handle HTTPS and HTTP/2 requests and cache the pages all in one application.

LiteMage Cache operates by taking information from Magento and instructing the LiteSpeed server on how to cache the page. Once the server knows how to cache it, future requests to the same page will be served directly from the server. Requests will never hit the Magento backend until a change occurs.

\* For clustered setups, LiteSpeed Load Balancer is needed.

## Installation
Todo

## Configuration

No changes to your Magento 2 configurations are necessary. The LiteMage plugin utilizes the same configurations as the built in cache.

## Todo
* Add Cache Warm up
* Add Customizable Configurations
* Add Unit Tests

